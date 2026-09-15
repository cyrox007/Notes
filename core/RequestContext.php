<?php

declare(strict_types=1);

namespace Core;

final class RequestContext
{
    /** @return list<string> */
    public static function trustedProxyIps(): array
    {
        $configured = (string) (getenv('TRUSTED_PROXY_IPS') ?: '');
        $result = [];
        foreach (explode(',', $configured) as $value) {
            $value = trim($value);
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false) {
                $result[] = $value;
            }
        }
        return array_values(array_unique($result));
    }

    public static function remoteAddress(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '';
    }

    public static function isTrustedProxy(?string $remoteAddress = null): bool
    {
        $remoteAddress ??= self::remoteAddress();
        return $remoteAddress !== '' && in_array($remoteAddress, self::trustedProxyIps(), true);
    }

    public static function clientIp(): string
    {
        $remote = self::remoteAddress();
        if (!self::isTrustedProxy($remote)) {
            return $remote !== '' ? $remote : 'unknown';
        }

        $realIp = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP) !== false) {
            return $realIp;
        }

        $forwardedFor = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwardedFor !== '') {
            $candidate = trim(explode(',', $forwardedFor, 2)[0] ?? '');
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return $remote !== '' ? $remote : 'unknown';
    }

    public static function requestScheme(): string
    {
        $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
        if ($https !== '' && !in_array($https, ['off', '0', 'false'], true)) {
            return 'https';
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return 'https';
        }

        if (self::isTrustedProxy()) {
            $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
            if ($forwardedProto !== '') {
                $forwardedProto = trim(explode(',', $forwardedProto, 2)[0] ?? '');
                if (in_array($forwardedProto, ['http', 'https'], true)) {
                    return $forwardedProto;
                }
            }
        }

        return 'http';
    }

    public static function publicScheme(): string
    {
        $siteUrl = trim((string) (getenv('SITEURL') ?: ''));
        $configured = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
        if (in_array($configured, ['http', 'https'], true)) {
            return $configured;
        }
        return self::requestScheme();
    }

    public static function isPublicHttps(): bool
    {
        return self::publicScheme() === 'https';
    }

    public static function cookiePath(): string
    {
        $raw = trim((string) (getenv('BASE_PATH') ?: '/'));
        if ($raw === '' || $raw === '/') {
            return '/';
        }

        if (str_contains($raw, "\0") || str_contains($raw, '..') || preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            return '/';
        }

        $segments = array_values(array_filter(explode('/', trim($raw, '/')), static fn (string $segment): bool => $segment !== ''));
        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z0-9._~-]+$/', $segment) !== 1) {
                return '/';
            }
        }

        return '/' . implode('/', $segments) . '/';
    }
}
