<?php

declare(strict_types=1);

namespace Core;

use InvalidArgumentException;

final class WebSocketEndpoint
{
    public static function resolvePublicUrl(
        string $configuredUrl,
        string $siteUrl,
        string $basePath = '/'
    ): string {
        $configuredUrl = trim($configuredUrl);
        if ($configuredUrl !== '') {
            return self::validateConfiguredUrl($configuredUrl);
        }

        $siteUrl = trim($siteUrl);
        $parts = parse_url($siteUrl);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('SITEURL must be a valid absolute http(s) URL');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException('SITEURL must include http(s) scheme and host');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('SITEURL userinfo is not allowed for WebSocket endpoint derivation');
        }

        $wsScheme = $scheme === 'https' ? 'wss' : 'ws';
        $authority = self::formatHost($host);
        if (isset($parts['port'])) {
            $authority .= ':' . (int) $parts['port'];
        }

        $path = self::socketPath($basePath);
        return sprintf('%s://%s%s', $wsScheme, $authority, $path);
    }

    private static function validateConfiguredUrl(string $url): string
    {
        if (preg_match('/[\r\n\x00]/', $url) === 1) {
            throw new InvalidArgumentException('WS_PUBLIC_URL contains forbidden control characters');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('WS_PUBLIC_URL must be a valid absolute ws(s) URL');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['ws', 'wss'], true) || $host === '') {
            throw new InvalidArgumentException('WS_PUBLIC_URL must use ws:// or wss:// and include a host');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('WS_PUBLIC_URL userinfo is not allowed');
        }

        return $url;
    }

    private static function socketPath(string $basePath): string
    {
        $basePath = trim($basePath);
        if ($basePath === '' || $basePath === '/') {
            return '/ws';
        }

        if (preg_match('/[\r\n\x00?#]/', $basePath) === 1) {
            throw new InvalidArgumentException('BASE_PATH is invalid for WebSocket endpoint derivation');
        }

        return '/' . trim($basePath, '/') . '/ws';
    }

    private static function formatHost(string $host): string
    {
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            return '[' . $host . ']';
        }
        return $host;
    }
}
