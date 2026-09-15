<?php

declare(strict_types=1);

namespace Core;

use InvalidArgumentException;

final class RedirectPolicy
{
    public static function resolveLocal(string $target, string $siteUrl): string
    {
        $target = trim($target);
        if ($target === '' || preg_match('/[\r\n\x00]/', $target) === 1) {
            throw new InvalidArgumentException('Redirect target is empty or contains control characters');
        }

        if (str_starts_with($target, '//')) {
            throw new InvalidArgumentException('Protocol-relative redirects are not allowed');
        }

        if (str_starts_with($target, '/')) {
            return self::validateRelativeTarget($target);
        }

        if (filter_var($target, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Redirect target must be a local path or same-origin URL');
        }

        $targetParts = parse_url($target);
        $siteParts = parse_url($siteUrl);
        if (!is_array($targetParts) || !is_array($siteParts)) {
            throw new InvalidArgumentException('Redirect origin cannot be resolved');
        }

        $targetScheme = strtolower((string) ($targetParts['scheme'] ?? ''));
        $siteScheme = strtolower((string) ($siteParts['scheme'] ?? ''));
        if (!in_array($targetScheme, ['http', 'https'], true) || $targetScheme !== $siteScheme) {
            throw new InvalidArgumentException('Redirect scheme does not match configured application origin');
        }

        $targetHost = strtolower((string) ($targetParts['host'] ?? ''));
        $siteHost = strtolower((string) ($siteParts['host'] ?? ''));
        if ($targetHost === '' || $siteHost === '' || !hash_equals($siteHost, $targetHost)) {
            throw new InvalidArgumentException('External redirect host is not allowed');
        }

        $targetPort = self::effectivePort($targetParts, $targetScheme);
        $sitePort = self::effectivePort($siteParts, $siteScheme);
        if ($targetPort !== $sitePort) {
            throw new InvalidArgumentException('Redirect port does not match configured application origin');
        }

        if (isset($targetParts['user']) || isset($targetParts['pass'])) {
            throw new InvalidArgumentException('Redirect target must not contain userinfo');
        }

        $local = (string) ($targetParts['path'] ?? '/');
        if (isset($targetParts['query'])) {
            $local .= '?' . $targetParts['query'];
        }
        if (isset($targetParts['fragment'])) {
            $local .= '#' . $targetParts['fragment'];
        }

        return self::validateRelativeTarget($local);
    }

    private static function validateRelativeTarget(string $target): string
    {
        if ($target === '' || $target[0] !== '/' || str_starts_with($target, '//')) {
            throw new InvalidArgumentException('Redirect target must stay on the local origin');
        }

        $path = parse_url($target, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            throw new InvalidArgumentException('Redirect path is invalid');
        }

        if (preg_match('/(?:^|\/)\.\.(?:\/|$)/', $path) === 1) {
            throw new InvalidArgumentException('Redirect path traversal is not allowed');
        }

        return $target;
    }

    /** @param array<string,mixed> $parts */
    private static function effectivePort(array $parts, string $scheme): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return $scheme === 'https' ? 443 : 80;
    }
}
