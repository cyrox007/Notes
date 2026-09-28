<?php

declare(strict_types=1);

namespace Core;

final class Helper
{
    public static function generateToken(string $sessionKey = '_csrf_token'): string
    {
        $token = $_SESSION[$sessionKey] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[$sessionKey] = $token;
        }

        return $token;
    }

    public static function getToken(string $sessionKey = '_csrf_token'): string
    {
        $token = $_SESSION[$sessionKey] ?? null;
        return is_string($token) && $token !== ''
            ? $token
            : self::generateToken($sessionKey);
    }

    public static function validateToken(string $token, string $sessionKey = '_csrf_token'): bool
    {
        $expected = $_SESSION[$sessionKey] ?? null;
        return is_string($expected)
            && $expected !== ''
            && hash_equals($expected, $token);
    }

    public static function getCSRFInputTag(): string
    {
        $token = self::getToken();

        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
            . '">';
    }
}
