<?php

declare(strict_types=1);

$protocolRoot = dirname(__DIR__, 2);
require_once $protocolRoot . '/app/socket/SocketHandshake.php';
require_once $protocolRoot . '/app/socket/SocketFrameCodec.php';

use App\Sockets\SocketFrameCodec;
use App\Sockets\SocketHandshake;

function nativeWsProtocolAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function nativeWsMaskedFrame(int $opcode, string $payload, bool $fin = true, string $mask = "\x37\xfa\x21\x3d"): string
{
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

$partialHandshake = "GET /ws?ticket=test HTTP/1.1\r\nHost: example.test\r\nUpgrade: websocket\r\n";
nativeWsProtocolAssert(SocketHandshake::tryParse($partialHandshake) === null, 'partial handshake should wait for more bytes');

$handshakeRequest = "GET /workspace/ws?ticket=abc123 HTTP/1.1\r\n"
    . "Host: example.test\r\n"
    . "Upgrade: websocket\r\n"
    . "Connection: keep-alive, Upgrade\r\n"
    . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
    . "Sec-WebSocket-Version: 13\r\n"
    . "Origin: https://example.test\r\n\r\n"
    . "trailing";
$handshake = SocketHandshake::tryParse($handshakeRequest);
nativeWsProtocolAssert(is_array($handshake), 'valid WebSocket handshake was not parsed');
nativeWsProtocolAssert($handshake['path'] === '/workspace/ws', 'handshake path mismatch');
nativeWsProtocolAssert(($handshake['query']['ticket'] ?? null) === 'abc123', 'handshake ticket query mismatch');
nativeWsProtocolAssert($handshake['origin'] === 'https://example.test', 'handshake origin mismatch');
nativeWsProtocolAssert(
    str_contains($handshake['response'], 'Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo='),
    'RFC 6455 handshake accept hash mismatch'
);
nativeWsProtocolAssert(substr($handshakeRequest, $handshake['consumed']) === 'trailing', 'handshake consumed wrong byte count');

$invalidVersionRejected = false;
try {
    SocketHandshake::tryParse(str_replace('Sec-WebSocket-Version: 13', 'Sec-WebSocket-Version: 12', $handshakeRequest));
} catch (RuntimeException) {
    $invalidVersionRejected = true;
}
nativeWsProtocolAssert($invalidVersionRejected, 'unsupported WebSocket version was accepted');

// RFC 6455 §5.7 masked "Hello" client frame example.
$buffer = "\x81\x85\x37\xfa\x21\x3d\x7f\x9f\x4d\x51\x58";
$frames = SocketFrameCodec::decodeClientFrames($buffer);
nativeWsProtocolAssert(count($frames) === 1, 'masked text frame was not decoded');
nativeWsProtocolAssert($frames[0]['fin'] === true, 'decoded FIN flag mismatch');
nativeWsProtocolAssert($frames[0]['opcode'] === SocketFrameCodec::OPCODE_TEXT, 'decoded opcode mismatch');
nativeWsProtocolAssert($frames[0]['payload'] === 'Hello', 'masked frame payload mismatch');
nativeWsProtocolAssert($buffer === '', 'decoded frame bytes were not consumed');

$extendedPayload = str_repeat('x', 200);
$buffer = nativeWsMaskedFrame(SocketFrameCodec::OPCODE_TEXT, $extendedPayload);
$frames = SocketFrameCodec::decodeClientFrames($buffer, 1024);
nativeWsProtocolAssert(($frames[0]['payload'] ?? null) === $extendedPayload, '16-bit payload length frame failed');

$completeFrame = nativeWsMaskedFrame(SocketFrameCodec::OPCODE_TEXT, 'partial-test');
$buffer = substr($completeFrame, 0, -3);
$before = $buffer;
$frames = SocketFrameCodec::decodeClientFrames($buffer);
nativeWsProtocolAssert($frames === [], 'incomplete frame was emitted');
nativeWsProtocolAssert($buffer === $before, 'incomplete frame bytes were consumed');
$buffer .= substr($completeFrame, -3);
$frames = SocketFrameCodec::decodeClientFrames($buffer);
nativeWsProtocolAssert(($frames[0]['payload'] ?? null) === 'partial-test', 'completed partial frame failed');

$unmaskedRejected = false;
$buffer = SocketFrameCodec::encodeText('client-must-mask');
try {
    SocketFrameCodec::decodeClientFrames($buffer);
} catch (RuntimeException) {
    $unmaskedRejected = true;
}
nativeWsProtocolAssert($unmaskedRejected, 'unmasked client frame was accepted');

$oversizedRejected = false;
$buffer = nativeWsMaskedFrame(SocketFrameCodec::OPCODE_TEXT, str_repeat('z', 64));
try {
    SocketFrameCodec::decodeClientFrames($buffer, 32);
} catch (RuntimeException) {
    $oversizedRejected = true;
}
nativeWsProtocolAssert($oversizedRejected, 'configured WebSocket frame limit was not enforced');

nativeWsProtocolAssert(SocketFrameCodec::encodeText('Hello') === "\x81\x05Hello", 'server text frame encoding mismatch');
nativeWsProtocolAssert(SocketFrameCodec::encodePong('ok') === "\x8a\x02ok", 'server pong frame encoding mismatch');
$close = SocketFrameCodec::encodeClose(1000, 'bye');
nativeWsProtocolAssert(substr($close, 0, 2) === "\x88\x05", 'server close frame header mismatch');
nativeWsProtocolAssert(unpack('ncode', substr($close, 2, 2))['code'] === 1000, 'server close code mismatch');

$decodedClose = SocketFrameCodec::decodeClosePayload(pack('n', 1000) . 'bye');
nativeWsProtocolAssert($decodedClose['code'] === 1000 && $decodedClose['reason'] === 'bye', 'valid close payload failed validation');
$emptyClose = SocketFrameCodec::decodeClosePayload('');
nativeWsProtocolAssert($emptyClose['code'] === null && $emptyClose['reason'] === '', 'empty close payload should be valid');

foreach ([
    "\x03",
    pack('n', 1005),
    pack('n', 1015),
    pack('n', 2000),
    pack('n', 1000) . "\xc3\x28",
] as $invalidClosePayload) {
    $rejected = false;
    try {
        SocketFrameCodec::decodeClosePayload($invalidClosePayload);
    } catch (RuntimeException) {
        $rejected = true;
    }
    nativeWsProtocolAssert($rejected, 'invalid close payload was accepted');
}

$invalidCloseEncodeRejected = false;
try {
    SocketFrameCodec::encodeClose(1005);
} catch (RuntimeException) {
    $invalidCloseEncodeRejected = true;
}
nativeWsProtocolAssert($invalidCloseEncodeRejected, 'reserved close code was encoded');

$codecSource = (string) file_get_contents($protocolRoot . '/app/socket/SocketFrameCodec.php');
$handshakeSource = (string) file_get_contents($protocolRoot . '/app/socket/SocketHandshake.php');
nativeWsProtocolAssert(!str_contains($codecSource, 'Workerman\\'), 'native frame codec depends on Workerman');
nativeWsProtocolAssert(!str_contains($handshakeSource, 'Workerman\\'), 'native handshake depends on Workerman');

echo "[OK] native RFC6455 handshake and frame codec contract\n";
