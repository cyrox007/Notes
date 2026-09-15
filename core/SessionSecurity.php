<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class SessionSecurity
{
    private const LAST_ACTIVITY_KEY = '_session_last_activity';
    private const STARTED_AT_KEY = '_session_started_at';
    private const CSRF_KEY = '_csrf_token';
    private const DEFAULT_LIFETIME = 3600;
    private const MIN_LIFETIME = 300;
    private const MAX_LIFETIME = 604800;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::enforceIdleLifetime();
            self::touch();
            return;
        }

        if (headers_sent($file, $line)) {
            throw new RuntimeException("Cannot start a secure session after headers were sent at {$file}:{$line}");
        }

        $lifetime = self::lifetimeSeconds();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) $lifetime);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => RequestContext::cookiePath(),
            'domain' => '',
            'secure' => RequestContext::isPublicHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('Unable to start a secure PHP session');
        }

        self::enforceIdleLifetime();
        self::touch();
    }

    public static function rotateAuthenticationBoundary(bool $clearApplicationState = false): void
    {
        self::start();

        if ($clearApplicationState) {
            $_SESSION = [];
        } else {
            unset($_SESSION[self::CSRF_KEY]);
        }

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to rotate session identifier');
        }

        unset($_SESSION[self::CSRF_KEY]);
        $_SESSION[self::STARTED_AT_KEY] = time();
        self::touch();
    }

    public static function invalidateAuthentication(): void
    {
        self::start();
        $_SESSION = [];

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to invalidate session identifier');
        }

        $_SESSION[self::STARTED_AT_KEY] = time();
        self::touch();
    }

    public static function lifetimeSeconds(): int
    {
        $raw = trim((string) (getenv('SESSION_LIFETIME') ?: ''));
        $value = ctype_digit($raw) ? (int) $raw : self::DEFAULT_LIFETIME;
        return max(self::MIN_LIFETIME, min(self::MAX_LIFETIME, $value));
    }

    public static function touch(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $now = time();
        if (!isset($_SESSION[self::STARTED_AT_KEY])) {
            $_SESSION[self::STARTED_AT_KEY] = $now;
        }
        $_SESSION[self::LAST_ACTIVITY_KEY] = $now;
    }

    private static function enforceIdleLifetime(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $lastActivity = (int) ($_SESSION[self::LAST_ACTIVITY_KEY] ?? 0);
        if ($lastActivity <= 0 || (time() - $lastActivity) <= self::lifetimeSeconds()) {
            return;
        }

        $_SESSION = [];
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to rotate expired session identifier');
        }
        $_SESSION[self::STARTED_AT_KEY] = time();
    }
}
