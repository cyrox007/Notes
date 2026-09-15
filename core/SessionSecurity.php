<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class SessionSecurity
{
    private static bool $configured = false;

    public static function configure(): void
    {
        if (self::$configured) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session security must be configured before session_start()');
        }

        $siteUrl = trim((string) Config::get('SITEURL', ''));
        $scheme = strtolower((string) (parse_url($siteUrl, PHP_URL_SCHEME) ?: ''));
        $secure = $scheme === 'https';

        $basePath = trim((string) (getenv('BASE_PATH') ?: '/'));
        if ($basePath === '' || $basePath[0] !== '/') {
            $basePath = '/' . ltrim($basePath, '/');
        }
        $basePath = '/' . trim($basePath, '/') . '/';
        if ($basePath === '//') {
            $basePath = '/';
        }

        self::setIni('session.use_strict_mode', '1');
        self::setIni('session.use_only_cookies', '1');
        self::setIni('session.use_trans_sid', '0');
        self::setIni('session.cookie_httponly', '1');
        self::setIni('session.cookie_samesite', 'Lax');
        self::setIni('session.cookie_secure', $secure ? '1' : '0');

        if (!session_set_cookie_params([
            'lifetime' => 0,
            'path' => $basePath,
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ])) {
            throw new RuntimeException('Unable to configure secure session cookie parameters');
        }

        self::$configured = true;
    }

    public static function isConfigured(): bool
    {
        return self::$configured;
    }

    private static function setIni(string $key, string $value): void
    {
        if (ini_set($key, $value) === false) {
            throw new RuntimeException("Unable to configure PHP session setting: {$key}");
        }
    }
}
