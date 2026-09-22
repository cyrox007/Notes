<?php

declare(strict_types=1);

namespace App\Sockets;

/**
 * Transport-neutral WebSocket connection boundary used by messenger handlers.
 *
 * Concrete transports are responsible only for frame I/O and connection
 * lifecycle. Authentication and per-connection messenger state stay here so
 * handlers do not depend on a specific WebSocket runtime.
 */
abstract class SocketConnection
{
    public bool $authenticated = false;
    public int $pingWithoutResponseCount = 0;
    public ?string $uid = null;
    public ?int $userId = null;
    public float $lastMessengerSearchAt = 0.0;

    abstract public function send(string $payload): void;

    abstract public function close(): void;

    /**
     * Transport-neutral close with an optional protocol code. Non-WebSocket
     * transports may ignore the code and simply close their response channel.
     */
    public function closeWithCode(int $code, string $reason = ''): void
    {
        $this->close();
    }

    abstract public function destroy(): void;
}
