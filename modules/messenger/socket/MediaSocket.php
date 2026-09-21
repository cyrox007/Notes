<?php

declare(strict_types=1);

namespace App\Sockets;

use App\Services\MessengerMediaService;
use App\Services\MessengerRealtimePublisher;
use App\Services\MessengerService;
use DomainException;
use InvalidArgumentException;

final class MediaSocket
{
    private MessengerRealtimePublisher $publisher;

    public function __construct(
        private ?MessengerMediaService $media = null,
        private ?MessengerService $messenger = null,
        ?MessengerRealtimePublisher $publisher = null
    ) {
        $this->media ??= new MessengerMediaService();
        $this->messenger ??= new MessengerService();
        $this->publisher = $publisher ?? new MessengerRealtimePublisher();
    }

    public function send(array $connections, SocketConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connections, $userUid, $payload): void {
            $attachmentUid = $this->requiredString($payload, 'attachment_uid');
            $caption = isset($payload['caption']) ? (string) $payload['caption'] : '';
            $replyToUid = isset($payload['reply_to_uid']) ? trim((string) $payload['reply_to_uid']) : null;

            $message = $this->media->send($userUid, $attachmentUid, $caption, $replyToUid);
            $dialogUid = (string) $message['dialog_uid'];
            unset($message['dialog_uid']);

            foreach ($this->messenger->participantUids($userUid, $dialogUid) as $participantUid) {
                $this->sendToUser($connections, $participantUid, [
                    'action' => 'send_message',
                    'dialog_uid' => $dialogUid,
                    'message' => $message,
                ]);
            }
        });
    }

    private function sendToUser(array $connections, string $userUid, array $payload): void
    {
        $this->publisher->publishToUser($connections, $userUid, $payload);
    }

    private function requiredString(array $payload, string $key): string
    {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException("Не указан параметр {$key}");
        }
        return $value;
    }

    private function guard(SocketConnection $connection, callable $callback): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException $e) {
            $this->error($connection, 'validation_error', $e->getMessage());
        } catch (DomainException $e) {
            $this->error($connection, 'forbidden', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('Media socket failure: ' . $e->getMessage());
            $this->error($connection, 'server_error', 'Не удалось отправить вложение');
        }
    }

    private function error(SocketConnection $connection, string $code, string $message): void
    {
        $this->sendPayload($connection, [
            'action' => 'error',
            'code' => $code,
            'message' => $message,
        ]);
    }

    private function sendPayload(SocketConnection $connection, array $payload): void
    {
        $connection->send(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
