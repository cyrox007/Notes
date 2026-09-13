<?php

declare(strict_types=1);

namespace App\Sockets;

use App\Services\MessengerForwardService;
use App\Services\MessengerSavedService;
use App\Services\MessengerService;
use DomainException;
use InvalidArgumentException;
use Workerman\Connection\TcpConnection;

final class ForwardSocket
{
    public function __construct(
        private ?MessengerForwardService $forwarder = null,
        private ?MessengerSavedService $saved = null,
        private ?MessengerService $messenger = null
    ) {
        $this->forwarder ??= new MessengerForwardService();
        $this->saved ??= new MessengerSavedService();
        $this->messenger ??= new MessengerService();
    }

    public function saved(array $connections, TcpConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connections, $userUid): void {
            $dialog = $this->saved->getOrCreate($userUid);
            $this->sendToUser($connections, $userUid, [
                'action' => 'saved_dialog',
                'dialog' => $dialog,
            ]);
        });
    }

    public function save_message(array $connections, TcpConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connections, $userUid, $payload): void {
            $message = $this->forwarder->save(
                $userUid,
                $this->requiredString($payload, 'message_uid')
            );
            $dialogUid = (string) $message['dialog_uid'];
            unset($message['dialog_uid']);

            $this->sendToUser($connections, $userUid, [
                'action' => 'send_message',
                'dialog_uid' => $dialogUid,
                'message' => $message,
            ]);
            $this->sendToUser($connections, $userUid, [
                'action' => 'message_saved',
                'dialog_uid' => $dialogUid,
                'message_uid' => (string) $message['uid'],
            ]);
        });
    }

    public function forward(array $connections, TcpConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connections, $userUid, $payload): void {
            $message = $this->forwarder->forward(
                $userUid,
                $this->requiredString($payload, 'message_uid'),
                $this->requiredString($payload, 'dialog_uid')
            );
            $dialogUid = (string) $message['dialog_uid'];
            unset($message['dialog_uid']);

            foreach ($this->messenger->participantUids($userUid, $dialogUid) as $participantUid) {
                $this->sendToUser($connections, $participantUid, [
                    'action' => 'send_message',
                    'dialog_uid' => $dialogUid,
                    'message' => $message,
                ]);
            }

            $this->sendToUser($connections, $userUid, [
                'action' => 'message_forwarded',
                'dialog_uid' => $dialogUid,
                'message_uid' => (string) $message['uid'],
            ]);
        });
    }

    private function requiredString(array $payload, string $key): string
    {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException("Не указан параметр {$key}");
        }
        return $value;
    }

    private function sendToUser(array $connections, string $userUid, array $payload): void
    {
        foreach ($connections[$userUid] ?? [] as $userConnection) {
            if ($userConnection instanceof TcpConnection) {
                $this->send($userConnection, $payload);
            }
        }
    }

    private function guard(TcpConnection $connection, callable $callback): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException $e) {
            $this->error($connection, 'validation_error', $e->getMessage());
        } catch (DomainException $e) {
            $this->error($connection, 'forbidden', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('Forward socket failure: ' . $e->getMessage());
            $this->error($connection, 'server_error', 'Не удалось переслать сообщение');
        }
    }

    private function error(TcpConnection $connection, string $code, string $message): void
    {
        $this->send($connection, [
            'action' => 'error',
            'code' => $code,
            'message' => $message,
        ]);
    }

    private function send(TcpConnection $connection, array $payload): void
    {
        $connection->send(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
