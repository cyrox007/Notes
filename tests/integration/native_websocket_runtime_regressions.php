<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/socket/SocketConnection.php';
require_once $root . '/app/socket/SocketFrameCodec.php';
require_once $root . '/app/socket/NativeSocketConnection.php';
require_once $root . '/app/socket/SocketHandshake.php';
require_once $root . '/app/socket/NativeMessengerServer.php';

use App\Sockets\NativeMessengerServer;
use App\Sockets\NativeSocketConnection;
use App\Sockets\SocketFrameCodec;

function wsRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

/** @return array{0:resource,1:resource} */
function wsRuntimeSocketPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!is_array($pair) || count($pair) !== 2) {
        throw new RuntimeException('Unable to create local socket pair');
    }
    stream_set_blocking($pair[0], false);
    stream_set_blocking($pair[1], false);
    return [$pair[0], $pair[1]];
}

function wsRuntimeMaskedFrame(int $opcode, string $payload, bool $fin = true): string
{
    $mask = "\x12\x34\x56\x78";
    $length = strlen($payload);
    $first = ($fin ? 0x80 : 0x00) | $opcode;
    if ($length <= 125) {
        $header = chr($first) . chr(0x80 | $length);
    } elseif ($length <= 0xFFFF) {
        $header = chr($first) . chr(0x80 | 126) . pack('n', $length);
    } else {
        $header = chr($first) . chr(0x80 | 127) . pack('N2', 0, $length);
    }

    $masked = $payload;
    for ($i = 0; $i < $length; $i++) {
        $masked[$i] = chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
    }
    return $header . $mask . $masked;
}

function wsRuntimePrivateSet(object $object, string $property, mixed $value): void
{
    $reflection = new ReflectionProperty($object, $property);
    $reflection->setAccessible(true);
    $reflection->setValue($object, $value);
}

function wsRuntimeInvoke(object $object, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invoke($object, ...$args);
}

// B02: a client that does not consume output must never grow the process heap
// without bound, and a closing connection has a hard drain deadline.
[$stream, $peer] = wsRuntimeSocketPair();
$slowClient = new NativeSocketConnection($stream, 1024, 0.01);
$slowClient->handshakeComplete = true;
$slowClient->send(str_repeat('a', 700));
wsRuntimeAssert(!$slowClient->isClosing(), 'first bounded send unexpectedly closed client');
$slowClient->send(str_repeat('b', 700));
wsRuntimeAssert($slowClient->isClosing(), 'output overflow did not schedule backpressure close');
wsRuntimeAssert($slowClient->pendingOutputBytes() <= 1024, 'pending output exceeded configured cap');
$slowClient->enforceCloseDeadline(microtime(true) + 1.0);
wsRuntimeAssert($slowClient->isDestroyed(), 'closing client survived beyond forced-close deadline');
@fclose($peer);

// B03: once an earlier frame schedules protocol close, later frames from the
// same already-decoded batch must not dispatch. Invalid UTF-8 text followed by
// PING should queue only one CLOSE frame, never a PONG.
[$stream, $peer] = wsRuntimeSocketPair();
$batchClient = new NativeSocketConnection($stream);
$batchClient->handshakeComplete = true;
$batchClient->appendInput(
    wsRuntimeMaskedFrame(SocketFrameCodec::OPCODE_TEXT, "\xc3\x28")
    . wsRuntimeMaskedFrame(SocketFrameCodec::OPCODE_PING, 'late-ping')
);
$serverReflection = new ReflectionClass(NativeMessengerServer::class);
$server = $serverReflection->newInstanceWithoutConstructor();
wsRuntimePrivateSet($server, 'maxPayloadBytes', SocketFrameCodec::DEFAULT_MAX_PAYLOAD_BYTES);
wsRuntimeInvoke($server, 'processFrames', $batchClient);
$expectedClose = SocketFrameCodec::encodeClose(1007, 'Invalid UTF-8');
wsRuntimeAssert($batchClient->isClosing(), 'invalid UTF-8 did not schedule close');
wsRuntimeAssert(
    $batchClient->pendingOutputBytes() === strlen($expectedClose),
    'later frame was dispatched after protocol close was scheduled'
);
$batchClient->destroy();
@fclose($peer);

// B05: a database/user lookup failure during handshake is isolated to that
// connection and converted to HTTP 503 rather than escaping the shared loop.
[$stream, $peer] = wsRuntimeSocketPair();
$handshakeClient = new NativeSocketConnection($stream);
$handshakeClient->appendInput(
    "GET /ws?ticket=audit-test HTTP/1.1\r\n"
    . "Host: example.test\r\n"
    . "Upgrade: websocket\r\n"
    . "Connection: Upgrade\r\n"
    . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
    . "Sec-WebSocket-Version: 13\r\n\r\n"
);
$server = $serverReflection->newInstanceWithoutConstructor();
wsRuntimePrivateSet($server, 'allowedOrigins', []);
wsRuntimePrivateSet($server, 'ticketValidator', static fn (string $ticket): ?int => 7);
wsRuntimePrivateSet($server, 'messengerPermissionChecker', static fn (int $userId): bool => true);
wsRuntimePrivateSet($server, 'userUidResolver', static function (int $userId): ?string {
    throw new RuntimeException('simulated database outage');
});

$escaped = false;
try {
    $accepted = wsRuntimeInvoke($server, 'processHandshake', $handshakeClient);
} catch (Throwable) {
    $escaped = true;
    $accepted = null;
}
wsRuntimeAssert(!$escaped, 'user lookup exception escaped handshake boundary');
wsRuntimeAssert($accepted === false, 'failed user lookup unexpectedly completed handshake');
wsRuntimeAssert($handshakeClient->isClosing(), 'failed user lookup did not schedule connection close');
$handshakeClient->flush();
usleep(10_000);
$response = (string) @fread($peer, 4096);
wsRuntimeAssert(str_contains($response, 'HTTP/1.1 503 Service Unavailable'), 'handshake DB failure was not converted to HTTP 503');
@fclose($peer);

echo "[OK] native WebSocket runtime audit regressions\n";
