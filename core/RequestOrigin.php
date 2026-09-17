<?php

declare(strict_types=1);

namespace Core;

/**
 * Browser-facing request origin and client-address resolution.
 *
 * Forwarded headers are security-sensitive input. They are honored only when
 * REMOTE_ADDR is explicitly listed in TRUSTED_PROXY_IPS. Direct requests never
 * get to override their transport/client address by supplying proxy headers.
 */
final class RequestOrigin
{
    /** @param array<string,mixed> $server */
    public static function isSecure(array $server, string $fallbackSiteUrl = ''): bool
    {
        $https = strtolower(trim((string) ($server['HTTPS'] ?? '')));
        if (in_array($https, ['on', '1', 'true'], true)) {
            return true;
        }

        if (self::isTrustedProxy($server)) {
            $forwardedProto = self::firstForwardedProto((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
            if ($forwardedProto !== null) {
                return $forwardedProto === 'https';
            }

            $forwarded = (string) ($server['HTTP_FORWARDED'] ?? '');
            if ($forwarded !== ''
                && preg_match('/(?:^|[;,]\s*)proto=(?:"?)(https?)(?:"?)(?:[;,]|$)/i', $forwarded, $matches) === 1
            ) {
                return strtolower((string) $matches[1]) === 'https';
            }
        }

        $requestScheme = strtolower(trim((string) ($server['REQUEST_SCHEME'] ?? '')));
        if (in_array($requestScheme, ['http', 'https'], true)) {
            return $requestScheme === 'https';
        }

        $serverPort = (int) ($server['SERVER_PORT'] ?? 0);
        if ($serverPort > 0) {
            return $serverPort === 443;
        }

        return strtolower((string) (parse_url($fallbackSiteUrl, PHP_URL_SCHEME) ?: '')) === 'https';
    }

    /** @param array<string,mixed> $server */
    public static function clientIp(array $server): string
    {
        $remoteAddress = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($remoteAddress === '') {
            return 'unknown';
        }

        if (!self::isTrustedProxy($server)) {
            return $remoteAddress;
        }

        $candidate = trim((string) ($server['HTTP_X_REAL_IP'] ?? ''));
        if ($candidate === '') {
            $forwarded = trim((string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''));
            $candidate = trim(explode(',', $forwarded, 2)[0] ?? '');
        }

        return $candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false
            ? $candidate
            : $remoteAddress;
    }

    /** @param array<string,mixed> $server */
    public static function isTrustedProxy(array $server): bool
    {
        $remoteAddress = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($remoteAddress === '') {
            return false;
        }

        return in_array($remoteAddress, self::trustedProxyIps(), true);
    }

    /** @return list<string> */
    private static function trustedProxyIps(): array
    {
        $configured = (string) (getenv('TRUSTED_PROXY_IPS') ?: '');
        $values = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $configured)
        )));

        return array_values(array_filter(
            $values,
            static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_IP) !== false
        ));
    }

    private static function firstForwardedProto(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $first = strtolower(trim(explode(',', $value, 2)[0]));
        return in_array($first, ['http', 'https'], true) ? $first : null;
    }
}
