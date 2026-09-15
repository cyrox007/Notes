<?php

declare(strict_types=1);

namespace App\Sockets;

use App\Services\MessengerSearchService;
use DomainException;
use InvalidArgumentException;

final class SearchSocket
{
    private const MIN_INTERVAL_SECONDS = 0.12;

    public function __construct(private ?MessengerSearchService $search = null)
    {
        $this->search ??= new MessengerSearchService();
    }

    public function all(array $connections, SocketConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connection, $userUid, $payload): void {
            $this->throttle($connection);
            $query = trim((string) ($payload['query'] ?? ''));

            $this->send($connection, [
                'action' => 'search_all',
                'query' => $query,
                'dialogs' => $this->search->dialogs($userUid, $query, 15),
                'messages' => $this->search->messages($userUid, $query, null, 30),
            ]);
        });
    }

    public function messages(array $connections, SocketConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connection, $userUid, $payload): void {
            $this->throttle($connection);
            $query = trim((string) ($payload['query'] ?? ''));
            $dialogUid = isset($payload['dialog_uid']) ? trim((string) $payload['dialog_uid']) : null;
            $limit = isset($payload['limit']) ? (int) $payload['limit'] : 30;

            $this->send($connection, [
                'action' => 'search_messages',
                'query' => $query,
                'dialog_uid' => $dialogUid ?: null,
                'messages' => $this->search->messages($userUid, $query, $dialogUid ?: null, $limit),
            ]);
        });
    }

    public function dialogs(array $connections, SocketConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connection, $userUid, $payload): void {
            $this->throttle($connection);
            $query = trim((string) ($payload['query'] ?? ''));
            $limit = isset($payload['limit']) ? (int) $payload['limit'] : 20;

            $this->send($connection, [
                'action' => 'search_dialogs',
                'query' => $query,
                'dialogs' => $this->search->dialogs($userUid, $query, $limit),
            ]);
        });
    }

    private function throttle(SocketConnection $connection): void
    {
        $now = microtime(true);
        $last = $connection->lastMessengerSearchAt;
        if ($last > 0 && ($now - $last) < self::MIN_INTERVAL_SECONDS) {
            throw new InvalidArgumentException('Слишком частые поисковые запросы');
        }
        $connection->lastMessengerSearchAt = $now;
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
            error_log('Messenger search failure: ' . $e->getMessage());
            $this->error($connection, 'server_error', 'Не удалось выполнить поиск');
        }
    }

    private function error(SocketConnection $connection, string $code, string $message): void
    {
        $this->send($connection, [
            'action' => 'error',
            'code' => $code,
            'message' => $message,
        ]);
    }

    private function send(SocketConnection $connection, array $payload): void
    {
        $connection->send(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
