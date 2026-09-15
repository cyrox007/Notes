<?php

declare(strict_types=1);

namespace App\Sockets;

use Workerman\Connection\TcpConnection;

/**
 * Compatibility adapter for the current Workerman transport.
 *
 * Messenger handlers depend only on SocketConnection; replacing Workerman now
 * requires changing the server bootstrap/transport rather than every handler.
 */
final class WorkermanConnectionAdapter extends SocketConnection
{
    public function __construct(private TcpConnection $connection)
    {
    }

    public function send(string $payload): void
    {
        $this->connection->send($payload);
    }

    public function close(): void
    {
        $this->connection->close();
    }

    public function destroy(): void
    {
        $this->connection->destroy();
    }

    public function raw(): TcpConnection
    {
        return $this->connection;
    }

    public function id(): int
    {
        return spl_object_id($this->connection);
    }
}
