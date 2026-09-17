<?php

declare(strict_types=1);

namespace App\Sockets;

use RuntimeException;

/**
 * Minimal RFC 6455 HTTP upgrade parser for the internal Messenger listener.
 * TLS is intentionally terminated by the existing reverse proxy.
 */
final class SocketHandshake
{
    public const MAX_HEADER_BYTES = 16384;
    private const MAGIC_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * Parse one complete WebSocket upgrade request from a connection buffer.
     * Returns null while more bytes are needed.
     *
     * @return array{consumed:int,target:string,path:string,query:array<string,mixed>,origin:string,headers:array<string,string>,response:string}|null
     */
    public static function tryParse(string $buffer): ?array
    {
        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            if (strlen($buffer) > self::MAX_HEADER_BYTES) {
                throw new RuntimeException('WebSocket handshake headers are too large');
            }
            return null;
        }

        $consumed = $headerEnd + 4;
        if ($consumed > self::MAX_HEADER_BYTES) {
            throw new RuntimeException('WebSocket handshake headers are too large');
        }

        $headerBlock = substr($buffer, 0, $headerEnd);
        $lines = explode("\r\n", $headerBlock);
        $requestLine = array_shift($lines);
        if (!is_string($requestLine)
            || preg_match('#^GET\s+(\S+)\s+HTTP/1\.[01]$#', $requestLine, $matches) !== 1) {
            throw new RuntimeException('Invalid WebSocket HTTP request line');
        }

        $target = $matches[1];
        $headers = [];
        foreach ($lines as $line) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                throw new RuntimeException('Malformed WebSocket HTTP header');
            }

            $name = strtolower(trim(substr($line, 0, $separator)));
            $value = trim(substr($line, $separator + 1));
            if ($name === '') {
                throw new RuntimeException('Malformed WebSocket HTTP header name');
            }

            if (isset($headers[$name])) {
                $headers[$name] .= ', ' . $value;
            } else {
                $headers[$name] = $value;
            }
        }

        if (!self::containsToken($headers['upgrade'] ?? '', 'websocket')) {
            throw new RuntimeException('Missing WebSocket Upgrade header');
        }
        if (!self::containsToken($headers['connection'] ?? '', 'upgrade')) {
            throw new RuntimeException('Missing WebSocket Connection upgrade token');
        }
        if (trim($headers['sec-websocket-version'] ?? '') !== '13') {
            throw new RuntimeException('Unsupported WebSocket protocol version');
        }

        $key = trim($headers['sec-websocket-key'] ?? '');
        $decodedKey = base64_decode($key, true);
        if ($key === '' || $decodedKey === false || strlen($decodedKey) !== 16) {
            throw new RuntimeException('Invalid WebSocket key');
        }

        $parts = parse_url($target);
        if ($parts === false) {
            throw new RuntimeException('Invalid WebSocket request target');
        }
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        $accept = base64_encode(sha1($key . self::MAGIC_GUID, true));
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";

        return [
            'consumed' => $consumed,
            'target' => $target,
            'path' => $path,
            'query' => $query,
            'origin' => trim($headers['origin'] ?? ''),
            'headers' => $headers,
            'response' => $response,
        ];
    }

    private static function containsToken(string $value, string $expected): bool
    {
        foreach (explode(',', strtolower($value)) as $token) {
            if (trim($token) === strtolower($expected)) {
                return true;
            }
        }
        return false;
    }
}
