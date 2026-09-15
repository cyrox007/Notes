<?php

declare(strict_types=1);

namespace App\Sockets;

final class PingSocket
{
    public function index(
        array $connections,
        SocketConnection $connection,
        string $userUid,
        array $payload = []
    ): void {
        if (($payload['ping'] ?? null) === 'Pong') {
            $connection->pingWithoutResponseCount = 0;
        }
    }
}
