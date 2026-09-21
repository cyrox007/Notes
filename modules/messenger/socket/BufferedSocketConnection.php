<?php

declare(strict_types=1);

namespace App\Sockets;

final class BufferedSocketConnection extends SocketConnection
{
    /** @var list<array<string,mixed>> */
    private array $messages = [];
    private bool $closed = false;
    private bool $destroyed = false;

    public function send(string $payload): void
    {
        if ($this->destroyed) {
            return;
        }

        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            $this->messages[] = $decoded;
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function destroy(): void
    {
        $this->destroyed = true;
    }

    /** @return list<array<string,mixed>> */
    public function drain(): array
    {
        $messages = $this->messages;
        $this->messages = [];
        return $messages;
    }

    public function isClosed(): bool
    {
        return $this->closed || $this->destroyed;
    }
}
