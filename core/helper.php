<?php
namespace Core;
class Helper {
    public static function generateToken($sessionKey = '_csrf_token') {
        if (empty($_SESSION[$sessionKey])) {
            $_SESSION[$sessionKey] = bin2hex(random_bytes(32));
        }
        return $_SESSION[$sessionKey];
    }

    public static function getToken($sessionKey = '_csrf_token') {
        return isset($_SESSION[$sessionKey]) ? $_SESSION[$sessionKey] : self::generateToken($sessionKey);
    }

    public static function validateToken($token, $sessionKey = '_csrf_token') {
        return isset($_SESSION[$sessionKey]) && hash_equals($_SESSION[$sessionKey], $token);
    }

    public static function getCSRFInputTag() {
        $token = self::getToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}
