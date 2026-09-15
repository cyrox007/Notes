<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class SessionSecurity
{
    private const DEFAULT_LIFETIME_SECONDS = 2592000; // 30 days
    private const MAX_LIFETIME_SECONDS = 31536000; // 1 year hard ceiling

    private static bool $configured = false;
    private static int $lifetimeSeconds = self::DEFAULT_LIFETIME_SECONDS;

    /** @var array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string} */
    private static array $cookieParams = [];

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
        $lifetime = self::resolveLifetimeSeconds();

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
        self::setIni('session.cookie_lifetime', (string) $lifetime);

        if ($lifetime > 0) {
            $currentGcLifetime = max(0, (int) ini_get('session.gc_maxlifetime'));
            self::setIni('session.gc_maxlifetime', (string) max($currentGcLifetime, $lifetime));
        }

        self::$cookieParams = [
            'lifetime' => $lifetime,
            'path' => $basePath,
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if (!session_set_cookie_params(self::$cookieParams)) {
            throw new RuntimeException('Unable to configure secure session cookie parameters');
        }

        self::$lifetimeSeconds = $lifetime;
        self::$configured = true;
    }

    public static function isConfigured(): bool
    {
        return self::$configured;
    }

    public static function lifetimeSeconds(): int
    {
        return self::$lifetimeSeconds;
    }

    /**
     * Re-issue the current session cookie after login/session-id rotation so the
     * authenticated session survives a normal browser restart.
     */
    public static function refreshCurrentSessionCookie(): void
    {
        if (!self::$configured) {
            throw new RuntimeException('Session security is not configured');
        }
        if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
            throw new RuntimeException('Cannot refresh an inactive session');
        }
        if (headers_sent($file, $line)) {
            throw new RuntimeException("Cannot refresh session cookie after output at {$file}:{$line}");
        }

        $expires = self::$lifetimeSeconds > 0 ? time() + self::$lifetimeSeconds : 0;
        if (!setcookie(session_name(), session_id(), [
            'expires' => $expires,
            'path' => self::$cookieParams['path'],
            'domain' => self::$cookieParams['domain'],
            'secure' => self::$cookieParams['secure'],
            'httponly' => self::$cookieParams['httponly'],
            'samesite' => self::$cookieParams['samesite'],
        ])) {
            throw new RuntimeException('Unable to refresh authenticated session cookie');
        }
    }

    /**
     * Destroy both server-side session state and the persistent browser cookie.
     */
    public static function destroyCurrentSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (!headers_sent()) {
            $params = self::$cookieParams !== [] ? self::$cookieParams : session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => (string) ($params['path'] ?? '/'),
                'domain' => (string) ($params['domain'] ?? ''),
                'secure' => (bool) ($params['secure'] ?? false),
                'httponly' => (bool) ($params['httponly'] ?? true),
                'samesite' => (string) ($params['samesite'] ?? 'Lax'),
            ]);
        }

        session_destroy();
    }

    private static function resolveLifetimeSeconds(): int
    {
        $raw = getenv('SESSION_LIFETIME_SECONDS');
        if ($raw === false || trim((string) $raw) === '') {
            return self::DEFAULT_LIFETIME_SECONDS;
        }

        $raw = trim((string) $raw);
        if (preg_match('/^\d+$/', $raw) !== 1) {
            throw new RuntimeException('SESSION_LIFETIME_SECONDS must be a non-negative integer');
        }

        $lifetime = (int) $raw;
        if ($lifetime > self::MAX_LIFETIME_SECONDS) {
            throw new RuntimeException('SESSION_LIFETIME_SECONDS exceeds the one-year safety ceiling');
        }

        return $lifetime;
    }

    private static function setIni(string $key, string $value): void
    {
        if (ini_set($key, $value) === false) {
            throw new RuntimeException("Unable to configure PHP session setting: {$key}");
        }
    }
}
