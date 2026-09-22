<?php

declare(strict_types=1);

namespace App\Sockets;

/**
 * In-process SocketConnection used by the HTTP realtime fallback.
 *
 * Messenger handlers remain transport-neutral: they emit the same JSON payloads
 * they would send over WebSocket, while this adapter buffers them for the HTTP
 * response instead of writing RFC6455 frames.
 */
final class BufferedSocketConnection extends SocketConnection
{
    /** @var list<array<string,mixed>> */
    private array $payloads = [];
    private bool $closed = false;

    public function send(string $payload): void
    {
        if ($this->closed || $payload === '') {
            return;
        }

        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            $this->payloads[] = $decoded;
        }
    }

    /** @return list<array<string,mixed>> */
    public function drainPayloads(): array
    {
        $payloads = $this->payloads;
        $this->payloads = [];
        return $payloads;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function closeWithCode(int $code, string $reason = ''): void
    {
        $this->closed = true;
    }

    public function destroy(): void
    {
        $this->closed = true;
        $this->payloads = [];
    }
}
