<?php

declare(strict_types=1);

namespace App\Sockets;

use RuntimeException;

/**
 * Non-blocking RFC6455 connection backed by a PHP stream resource.
 *
 * The event loop owns reads/writes. Messenger handlers only see the inherited
 * transport-neutral SocketConnection API.
 */
final class NativeSocketConnection extends SocketConnection
{
    /** @var resource|null */
    private $stream;
    private string $inputBuffer = '';
    private string $outputBuffer = '';
    private bool $closing = false;
    private bool $destroyed = false;

    public bool $handshakeComplete = false;
    public float $acceptedAt;
    public float $lastActivityAt;
    public ?int $fragmentOpcode = null;
    public string $fragmentBuffer = '';

    /** @param resource $stream */
    public function __construct($stream)
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('Native WebSocket connection requires a stream resource');
        }

        $this->stream = $stream;
        stream_set_blocking($this->stream, false);
        $this->acceptedAt = microtime(true);
        $this->lastActivityAt = $this->acceptedAt;
    }

    public function id(): int
    {
        if (!is_resource($this->stream)) {
            return 0;
        }
        return get_resource_id($this->stream);
    }

    /** @return resource|null */
    public function stream()
    {
        return is_resource($this->stream) ? $this->stream : null;
    }

    public function appendInput(string $chunk): void
    {
        $this->inputBuffer .= $chunk;
        $this->lastActivityAt = microtime(true);
    }

    public function inputBuffer(): string
    {
        return $this->inputBuffer;
    }

    public function consumeInput(int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }
        $this->inputBuffer = (string) substr($this->inputBuffer, $bytes);
    }

    /** @return list<array{fin:bool,opcode:int,payload:string}> */
    public function decodeFrames(int $maxPayloadBytes = SocketFrameCodec::DEFAULT_MAX_PAYLOAD_BYTES): array
    {
        return SocketFrameCodec::decodeClientFrames($this->inputBuffer, $maxPayloadBytes);
    }

    public function queueRaw(string $bytes): void
    {
        if ($this->destroyed) {
            return;
        }
        $this->outputBuffer .= $bytes;
    }

    public function send(string $payload): void
    {
        $this->queueRaw(SocketFrameCodec::encodeText($payload));
    }

    public function sendPing(string $payload = ''): void
    {
        $this->queueRaw(SocketFrameCodec::encodePing($payload));
    }

    public function sendPong(string $payload = ''): void
    {
        $this->queueRaw(SocketFrameCodec::encodePong($payload));
    }

    public function close(): void
    {
        $this->closeWithCode(1000);
    }

    public function closeWithCode(int $code, string $reason = ''): void
    {
        if ($this->destroyed || $this->closing) {
            return;
        }
        if ($this->handshakeComplete) {
            $this->queueRaw(SocketFrameCodec::encodeClose($code, $reason));
        }
        $this->closing = true;
    }

    public function rejectHttp(int $status, string $reason): void
    {
        if ($this->destroyed || $this->handshakeComplete) {
            return;
        }

        $safeReason = preg_replace('/[^A-Za-z0-9 ._-]/', '', $reason) ?: 'Rejected';
        $this->queueRaw(sprintf(
            "HTTP/1.1 %d %s\r\nConnection: close\r\nCache-Control: no-store\r\nContent-Length: 0\r\n\r\n",
            $status,
            $safeReason
        ));
        $this->closing = true;
    }

    public function hasPendingOutput(): bool
    {
        return $this->outputBuffer !== '';
    }

    public function isClosing(): bool
    {
        return $this->closing;
    }

    public function isDestroyed(): bool
    {
        return $this->destroyed;
    }

    public function flush(): void
    {
        if ($this->destroyed || !is_resource($this->stream)) {
            return;
        }

        if ($this->outputBuffer !== '') {
            $written = @fwrite($this->stream, $this->outputBuffer);
            if ($written === false) {
                $this->destroy();
                return;
            }
            if ($written > 0) {
                $this->outputBuffer = (string) substr($this->outputBuffer, $written);
                $this->lastActivityAt = microtime(true);
            }
        }

        if ($this->closing && $this->outputBuffer === '') {
            $this->destroy();
        }
    }

    public function destroy(): void
    {
        if ($this->destroyed) {
            return;
        }
        $this->destroyed = true;
        if (is_resource($this->stream)) {
            @stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
            @fclose($this->stream);
        }
        $this->stream = null;
        $this->inputBuffer = '';
        $this->outputBuffer = '';
    }
}
