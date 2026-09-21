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

        foreach ($connections[$userUid] ?? [] as $connection) {
            if (
                !$connection instanceof SocketConnection
                || $connection->userId === null
                || $connection->userId <= 0
            ) {
                continue;
            }

            // Never jump a live connection directly to the just-created event:
            // another PHP/WS process may have inserted an earlier event after
            // this connection's current cursor. Drain the journal in ID order so
            // switching/transient multi-process delivery cannot create gaps.
            $remainingBatches = 10;
            while ($connection->transportCursor < $eventId && $remainingBatches-- > 0) {
                $batch = $this->journal->readSince(
                    $connection->userId,
                    $connection->transportCursor,
                    200
                );
                if ($batch['events'] === []) {
                    break;
                }

                foreach ($batch['events'] as $event) {
                    $encoded = json_encode(
                        $event,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                    if (!is_string($encoded)) {
                        throw new RuntimeException('Messenger realtime event could not be encoded');
                    }
                    $connection->send($encoded);
                }

                $connection->transportCursor = max(
                    $connection->transportCursor,
                    (int) $batch['cursor']
                );
            }
        }

        return $eventId;
    }
}
