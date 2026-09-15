<?php

declare(strict_types=1);

namespace Core;

use InvalidArgumentException;

final class WebSocketEndpoint
{
    public static function bindHost(): string
    {
        $host = trim((string) (getenv('WS_HOST') ?: '127.0.0.1'));
        if ($host === '' || preg_match('/[\s\\\/]/', $host) === 1) {
            throw new InvalidArgumentException('WS_HOST is invalid');
        }
        return $host;
    }

    public static function port(): int
    {
        $raw = trim((string) (getenv('WS_PORT') ?: '27800'));
        if ($raw === '' || !ctype_digit($raw)) {
            throw new InvalidArgumentException('WS_PORT must be an integer');
        }

        $port = (int) $raw;
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('WS_PORT must be between 1 and 65535');
        }
        return $port;
    }

    public static function publicUrl(): string
    {
        $siteUrl = self::siteUrl();
        $configured = trim((string) (getenv('WS_PUBLIC_URL') ?: ''));
        if ($configured === '') {
            return self::sameOriginPublicUrl($siteUrl, self::basePath());
        }

        return self::normalizePublicUrl($configured, $siteUrl);
    }

    public static function siteUrl(): string
    {
        $siteUrl = rtrim(trim((string) (getenv('SITEURL') ?: 'http://localhost')), '/');
        $scheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
        $host = (string) parse_url($siteUrl, PHP_URL_HOST);
        $path = (string) (parse_url($siteUrl, PHP_URL_PATH) ?? '');
        $user = parse_url($siteUrl, PHP_URL_USER);
        $pass = parse_url($siteUrl, PHP_URL_PASS);

        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || ($path !== '' && $path !== '/') || $user !== null || $pass !== null) {
            throw new InvalidArgumentException('SITEURL must be an http(s) origin without a path or credentials');
        }

        $port = parse_url($siteUrl, PHP_URL_PORT);
        return $scheme . '://' . self::formatHost($host) . ($port !== null ? ':' . (int) $port : '');
    }

    public static function basePath(): string
    {
        $raw = trim((string) (getenv('BASE_PATH') ?: '/'));
        $path = '/' . trim($raw, '/') . '/';
        if ($path === '//') {
            return '/';
        }
        if (preg_match('#^/(?:[A-Za-z0-9._~-]+/)*$#', $path) !== 1) {
            throw new InvalidArgumentException('BASE_PATH is invalid');
        }
        return $path;
    }

    public static function sameOriginPublicUrl(string $siteUrl, string $basePath): string
    {
        $siteUrl = rtrim($siteUrl, '/');
        $scheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
        $host = (string) parse_url($siteUrl, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException('Cannot derive WebSocket URL from invalid SITEURL');
        }

        $port = parse_url($siteUrl, PHP_URL_PORT);
        $authority = self::formatHost($host) . ($port !== null ? ':' . (int) $port : '');
        $prefix = self::normalizeBasePathValue($basePath);
        $path = $prefix === '/' ? '/ws' : rtrim($prefix, '/') . '/ws';

        return ($scheme === 'https' ? 'wss' : 'ws') . '://' . $authority . $path;
    }

    public static function proxyPath(): string
    {
        $path = (string) (parse_url(self::publicUrl(), PHP_URL_PATH) ?? '');
        return $path !== '' ? $path : '/';
    }

    public static function proxyBackendUrl(): string
    {
        $host = self::bindHost();
        if ($host === '0.0.0.0' || $host === '*') {
            $host = '127.0.0.1';
        } elseif ($host === '::' || $host === '[::]') {
            $host = '::1';
        }

        return sprintf('http://%s:%d', self::formatHost($host), self::port());
    }

    public static function usesSameOriginProxy(): bool
    {
        $site = parse_url(self::siteUrl());
        $public = parse_url(self::publicUrl());
        if (!is_array($site) || !is_array($public)) {
            return false;
        }

        $siteScheme = strtolower((string) ($site['scheme'] ?? ''));
        $expectedWsScheme = $siteScheme === 'https' ? 'wss' : 'ws';
        if (strtolower((string) ($public['scheme'] ?? '')) !== $expectedWsScheme) {
            return false;
        }

        if (strtolower((string) ($site['host'] ?? '')) !== strtolower((string) ($public['host'] ?? ''))) {
            return false;
        }

        return self::effectivePort($siteScheme, $site['port'] ?? null)
            === self::effectivePort($expectedWsScheme, $public['port'] ?? null);
    }

    private static function normalizePublicUrl(string $url, string $siteUrl): string
    {
        $url = rtrim(trim($url), '/');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        $user = parse_url($url, PHP_URL_USER);
        $pass = parse_url($url, PHP_URL_PASS);
        $fragment = parse_url($url, PHP_URL_FRAGMENT);

        if (!in_array($scheme, ['ws', 'wss'], true) || $host === '' || $user !== null || $pass !== null || $fragment !== null) {
            throw new InvalidArgumentException('WS_PUBLIC_URL must be a ws(s) URL without credentials or fragment');
        }

        $siteScheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
        if ($siteScheme === 'https' && $scheme !== 'wss') {
            throw new InvalidArgumentException('HTTPS SITEURL requires a wss:// WS_PUBLIC_URL');
        }

        $port = parse_url($url, PHP_URL_PORT);
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        $normalized = $scheme . '://' . self::formatHost($host) . ($port !== null ? ':' . (int) $port : '');
        if ($path !== '') {
            $normalized .= '/' . ltrim($path, '/');
        }
        if ($query !== '') {
            $normalized .= '?' . $query;
        }
        return $normalized;
    }

    private static function normalizeBasePathValue(string $path): string
    {
        $path = '/' . trim($path, '/') . '/';
        return $path === '//' ? '/' : $path;
    }

    private static function effectivePort(string $scheme, mixed $port): int
    {
        if ($port !== null) {
            return (int) $port;
        }
        return in_array($scheme, ['https', 'wss'], true) ? 443 : 80;
    }

    private static function formatHost(string $host): string
    {
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            return '[' . $host . ']';
        }
        return $host;
    }
}
