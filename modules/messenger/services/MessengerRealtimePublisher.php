<?php

declare(strict_types=1);

namespace App\Services;

use App\Sockets\SocketConnection;
use RuntimeException;

final class MessengerRealtimePublisher
{
    public function __construct(private ?MessengerEventJournal $journal = null)
    {
        $this->journal ??= new MessengerEventJournal();
    }

    /**
     * Persist the event first, then deliver it to any WebSocket connections that
     * live in this process. Long-poll clients and WebSocket clients connected to
     * another process consume the same journal row by cursor.
     */
    public function publishToUser(array $connections, string $userUid, array $payload): ?int
    {
        $eventId = $this->journal->publishToUserUid($userUid, $payload);
        if ($eventId === null) {
            return null;
        }

        $payload['event_id'] = $eventId;
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('Messenger realtime event could not be encoded');
        }

        foreach ($connections[$userUid] ?? [] as $connection) {
            if (!$connection instanceof SocketConnection) {
                continue;
            }
            $connection->transportCursor = max($connection->transportCursor, $eventId);
            $connection->send($encoded);
        }

        return $eventId;
    }
}
