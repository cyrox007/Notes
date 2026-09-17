<?php

declare(strict_types=1);

namespace App\Sockets;

use RuntimeException;

/**
 * RFC 6455 frame encoder/decoder used by the native Messenger transport.
 * Client frames are required to be masked; server frames are never masked.
 */
final class SocketFrameCodec
{
    public const OPCODE_CONTINUATION = 0x0;
    public const OPCODE_TEXT = 0x1;
    public const OPCODE_BINARY = 0x2;
    public const OPCODE_CLOSE = 0x8;
    public const OPCODE_PING = 0x9;
    public const OPCODE_PONG = 0xA;
    public const DEFAULT_MAX_PAYLOAD_BYTES = 2_097_152;

    /**
     * Decode every complete client frame currently available in $buffer.
     * Incomplete trailing data remains in $buffer for the next socket read.
     *
     * @return list<array{fin:bool,opcode:int,payload:string}>
     */
    public static function decodeClientFrames(
        string &$buffer,
        int $maxPayloadBytes = self::DEFAULT_MAX_PAYLOAD_BYTES
    ): array {
        if ($maxPayloadBytes < 1) {
            throw new RuntimeException('WebSocket max payload must be positive');
        }

        $frames = [];
        $offset = 0;
        $bufferLength = strlen($buffer);

        while (($bufferLength - $offset) >= 2) {
            $frameStart = $offset;
            $first = ord($buffer[$offset]);
            $second = ord($buffer[$offset + 1]);
            $offset += 2;

            if (($first & 0x70) !== 0) {
                throw new RuntimeException('WebSocket RSV bits are not supported');
            }

            $fin = ($first & 0x80) !== 0;
            $opcode = $first & 0x0F;
            if (!in_array($opcode, [
                self::OPCODE_CONTINUATION,
                self::OPCODE_TEXT,
                self::OPCODE_BINARY,
                self::OPCODE_CLOSE,
                self::OPCODE_PING,
                self::OPCODE_PONG,
            ], true)) {
                throw new RuntimeException('Unsupported WebSocket opcode');
            }

            $masked = ($second & 0x80) !== 0;
            if (!$masked) {
                throw new RuntimeException('Client WebSocket frames must be masked');
            }

            $payloadLength = $second & 0x7F;
            if ($payloadLength === 126) {
                if (($bufferLength - $offset) < 2) {
                    $offset = $frameStart;
                    break;
                }
                $unpacked = unpack('nlength', substr($buffer, $offset, 2));
                $payloadLength = (int) ($unpacked['length'] ?? 0);
                $offset += 2;
                if ($payloadLength < 126) {
                    throw new RuntimeException('Non-canonical WebSocket frame length');
                }
            } elseif ($payloadLength === 127) {
                if (($bufferLength - $offset) < 8) {
                    $offset = $frameStart;
                    break;
                }
                $unpacked = unpack('Nhigh/Nlow', substr($buffer, $offset, 8));
                $high = (int) ($unpacked['high'] ?? 0);
                $low = (int) ($unpacked['low'] ?? 0);
                $offset += 8;
                if (($high & 0x80000000) !== 0) {
                    throw new RuntimeException('Invalid WebSocket 64-bit frame length');
                }
                if ($high !== 0 || $low < 65536) {
                    if ($high !== 0) {
                        throw new RuntimeException('WebSocket frame exceeds supported size');
                    }
                    throw new RuntimeException('Non-canonical WebSocket frame length');
                }
                $payloadLength = $low;
            }

            $controlFrame = ($opcode & 0x08) !== 0;
            if ($controlFrame && (!$fin || $payloadLength > 125)) {
                throw new RuntimeException('Invalid fragmented or oversized WebSocket control frame');
            }
            if ($payloadLength > $maxPayloadBytes) {
                throw new RuntimeException('WebSocket frame payload exceeds configured limit');
            }

            if (($bufferLength - $offset) < 4) {
                $offset = $frameStart;
                break;
            }
            $mask = substr($buffer, $offset, 4);
            $offset += 4;

            if (($bufferLength - $offset) < $payloadLength) {
                $offset = $frameStart;
                break;
            }

            $payload = substr($buffer, $offset, $payloadLength);
            $offset += $payloadLength;
            if ($payloadLength > 0) {
                $payload = self::applyMask($payload, $mask);
            }

            $frames[] = [
                'fin' => $fin,
                'opcode' => $opcode,
                'payload' => $payload,
            ];
        }

        if ($offset > 0) {
            $buffer = (string) substr($buffer, $offset);
        }

        return $frames;
    }

    /**
     * Validate and decode a CLOSE control-frame payload.
     *
     * RFC6455 allows an empty payload, or a two-byte valid status code followed
     * by an optional UTF-8 reason. A one-byte payload, reserved status code or
     * malformed UTF-8 reason is a protocol error.
     *
     * @return array{code:?int,reason:string}
     */
    public static function decodeClosePayload(string $payload): array
    {
        $length = strlen($payload);
        if ($length === 0) {
            return ['code' => null, 'reason' => ''];
        }
        if ($length === 1) {
            throw new RuntimeException('WebSocket close payload cannot be one byte');
        }

        $decoded = unpack('ncode', substr($payload, 0, 2));
        $code = (int) ($decoded['code'] ?? 0);
        if (!self::isValidCloseCode($code)) {
            throw new RuntimeException('Invalid WebSocket close status code');
        }

        $reason = (string) substr($payload, 2);
        if ($reason !== '' && preg_match('//u', $reason) !== 1) {
            throw new RuntimeException('WebSocket close reason must be valid UTF-8');
        }

        return ['code' => $code, 'reason' => $reason];
    }

    public static function encodeText(string $payload): string
    {
        return self::encodeFrame(self::OPCODE_TEXT, $payload);
    }

    public static function encodePong(string $payload = ''): string
    {
        if (strlen($payload) > 125) {
            throw new RuntimeException('WebSocket pong payload exceeds control-frame limit');
        }
        return self::encodeFrame(self::OPCODE_PONG, $payload);
    }

    public static function encodePing(string $payload = ''): string
    {
        if (strlen($payload) > 125) {
            throw new RuntimeException('WebSocket ping payload exceeds control-frame limit');
        }
        return self::encodeFrame(self::OPCODE_PING, $payload);
    }

    public static function encodeClose(int $code = 1000, string $reason = ''): string
    {
        if (!self::isValidCloseCode($code)) {
            throw new RuntimeException('Invalid WebSocket close code');
        }
        if (strlen($reason) > 123) {
            throw new RuntimeException('WebSocket close reason is too long');
        }
        if ($reason !== '' && preg_match('//u', $reason) !== 1) {
            throw new RuntimeException('WebSocket close reason must be valid UTF-8');
        }
        return self::encodeFrame(self::OPCODE_CLOSE, pack('n', $code) . $reason);
    }

    public static function encodeFrame(int $opcode, string $payload, bool $fin = true): string
    {
        if (!in_array($opcode, [
            self::OPCODE_CONTINUATION,
            self::OPCODE_TEXT,
            self::OPCODE_BINARY,
            self::OPCODE_CLOSE,
            self::OPCODE_PING,
            self::OPCODE_PONG,
        ], true)) {
            throw new RuntimeException('Unsupported WebSocket opcode');
        }

        $length = strlen($payload);
        $controlFrame = ($opcode & 0x08) !== 0;
        if ($controlFrame && (!$fin || $length > 125)) {
            throw new RuntimeException('Invalid WebSocket control frame');
        }

        $first = ($fin ? 0x80 : 0x00) | $opcode;
        if ($length <= 125) {
            return chr($first) . chr($length) . $payload;
        }
        if ($length <= 0xFFFF) {
            return chr($first) . chr(126) . pack('n', $length) . $payload;
        }

        // PHP strings cannot practically exceed the configured Messenger limits;
        // encode the 64-bit network length explicitly for protocol correctness.
        $high = intdiv($length, 0x100000000);
        $low = $length % 0x100000000;
        return chr($first) . chr(127) . pack('N2', $high, $low) . $payload;
    }

    private static function isValidCloseCode(int $code): bool
    {
        if ($code >= 3000 && $code <= 4999) {
            return true;
        }

        return $code >= 1000
            && $code <= 1014
            && !in_array($code, [1004, 1005, 1006], true);
    }

    private static function applyMask(string $payload, string $mask): string
    {
        $result = $payload;
        $length = strlen($payload);
        for ($i = 0; $i < $length; $i++) {
            $result[$i] = chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }
        return $result;
    }
}
