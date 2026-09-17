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
    public const DEFAULT_MAX_PENDING_OUTPUT_BYTES = 4_194_304;
    public const DEFAULT_CLOSE_DRAIN_TIMEOUT_SECONDS = 5.0;

    /** @var resource|null */
    private $stream;
    private int $resourceId;
    private string $inputBuffer = '';
    private string $outputBuffer = '';
    private bool $closing = false;
    private bool $destroyed = false;
    private ?float $closeDeadlineAt = null;

    public bool $handshakeComplete = false;
    public float $acceptedAt;
    public float $lastActivityAt;
    public ?int $fragmentOpcode = null;
    public string $fragmentBuffer = '';

    /** @param resource $stream */
    public function __construct(
        $stream,
        private int $maxPendingOutputBytes = self::DEFAULT_MAX_PENDING_OUTPUT_BYTES,
        private float $closeDrainTimeoutSeconds = self::DEFAULT_CLOSE_DRAIN_TIMEOUT_SECONDS
    ) {
        if (!is_resource($stream)) {
            throw new RuntimeException('Native WebSocket connection requires a stream resource');
        }
        if ($this->maxPendingOutputBytes < 1024 || $this->maxPendingOutputBytes > 67_108_864) {
            throw new RuntimeException('Invalid WebSocket pending-output limit');
        }
        if ($this->closeDrainTimeoutSeconds <= 0.0 || $this->closeDrainTimeoutSeconds > 60.0) {
            throw new RuntimeException('Invalid WebSocket close-drain timeout');
        }

        $this->stream = $stream;
        $this->resourceId = get_resource_id($stream);
        stream_set_blocking($this->stream, false);
        $this->acceptedAt = microtime(true);
        $this->lastActivityAt = $this->acceptedAt;
    }

    public function id(): int
    {
        return $this->resourceId;
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
        if ($this->destroyed || $this->closing || $bytes === '') {
            return;
        }

        $bytesLength = strlen($bytes);
        $pendingLength = strlen($this->outputBuffer);
        if ($bytesLength > $this->maxPendingOutputBytes
            || $pendingLength > ($this->maxPendingOutputBytes - $bytesLength)) {
            $this->beginBackpressureClose();
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
            $frame = SocketFrameCodec::encodeClose($code, $reason);
            if (strlen($this->outputBuffer) + strlen($frame) > $this->maxPendingOutputBytes) {
                // A close handshake must never make an already-saturated queue
                // unbounded. Discard stale application data and prioritize close.
                $this->outputBuffer = $frame;
            } else {
                $this->outputBuffer .= $frame;
            }
        }

        $this->beginClosing();
    }

    public function rejectHttp(int $status, string $reason): void
    {
        if ($this->destroyed || $this->handshakeComplete || $this->closing) {
            return;
        }

        $safeReason = preg_replace('/[^A-Za-z0-9 ._-]/', '', $reason) ?: 'Rejected';
        $response = sprintf(
            "HTTP/1.1 %d %s\r\nConnection: close\r\nCache-Control: no-store\r\nContent-Length: 0\r\n\r\n",
            $status,
            $safeReason
        );
        $this->outputBuffer = substr($response, 0, $this->maxPendingOutputBytes);
        $this->beginClosing();
    }

    public function hasPendingOutput(): bool
    {
        return $this->outputBuffer !== '';
    }

    public function pendingOutputBytes(): int
    {
        return strlen($this->outputBuffer);
    }

    public function isClosing(): bool
    {
        return $this->closing;
    }

    public function isDestroyed(): bool
    {
        return $this->destroyed;
    }

    public function enforceCloseDeadline(float $now): void
    {
        if ($this->destroyed || !$this->closing || $this->closeDeadlineAt === null) {
            return;
        }
        if ($now >= $this->closeDeadlineAt) {
            $this->destroy();
        }
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
        $this->fragmentBuffer = '';
        $this->fragmentOpcode = null;
    }

    private function beginBackpressureClose(): void
    {
        if ($this->destroyed || $this->closing) {
            return;
        }

        if ($this->handshakeComplete) {
            // 1013 asks a peer to retry later and is appropriate for a client
            // that cannot consume server output fast enough.
            $this->outputBuffer = SocketFrameCodec::encodeClose(1013, 'Client too slow');
        } else {
            $this->outputBuffer = "HTTP/1.1 503 Service Unavailable\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
        }
        $this->beginClosing();
    }

    private function beginClosing(): void
    {
        $this->closing = true;
        $this->closeDeadlineAt = microtime(true) + $this->closeDrainTimeoutSeconds;
    }
}
