<?php

declare(strict_types=1);

namespace App\Sockets;

use App\Services\MessengerReceiptService;
use App\Services\MessengerRealtimePublisher;
use DomainException;
use InvalidArgumentException;

final class ReceiptSocket
{
    private MessengerRealtimePublisher $publisher;

    public function __construct(
        private ?MessengerReceiptService $receipts = null,
        ?MessengerRealtimePublisher $publisher = null
    ) {
        $this->receipts ??= new MessengerReceiptService();
        $this->publisher = $publisher ?? new MessengerRealtimePublisher();
    }

    public function list(array $connections, SocketConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connection, $userUid, $payload): void {
            $dialogUid = $this->requiredString($payload, 'dialog_uid');
            $this->send($connection, [
                'action' => 'receipt_states',
                'dialog_uid' => $dialogUid,
                'receipts' => $this->receipts->listCursors($userUid, $dialogUid),
            ]);
        });
    }

    public function delivered(array $connections, SocketConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connections, $userUid, $payload): void {
            $dialogUid = $this->requiredString($payload, 'dialog_uid');
            $messageUid = $this->requiredString($payload, 'message_uid');
            $receipt = $this->receipts->markDelivered($userUid, $dialogUid, $messageUid);

            foreach ($this->receipts->participantUids($userUid, $dialogUid) as $participantUid) {
                $this->sendToUser($connections, $participantUid, [
                    'action' => 'delivered_update',
                    ...$receipt,
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
        } catch (InvalidArgumentException | DomainException $e) {
            $this->send($connection, ['action' => 'error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('Receipt socket failure: ' . $e->getMessage());
            $this->send($connection, ['action' => 'error', 'message' => 'Ошибка подтверждения доставки']);
        }
    }

    private function send(SocketConnection $connection, array $payload): void
    {
        $connection->send(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
