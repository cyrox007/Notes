<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class SecurityHeaders
{
    private static ?string $nonce = null;
    private static bool $applied = false;

    public static function nonce(): string
    {
        if (self::$nonce === null) {
            // 144 random bits; standard base64 is valid for CSP nonce-source and
            // 18 bytes avoids padding characters.
            self::$nonce = base64_encode(random_bytes(18));
        }

        return self::$nonce;
    }

    public static function policy(): string
    {
        $nonce = self::nonce();

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "connect-src 'self' ws: wss:",
            "script-src 'self' 'nonce-{$nonce}'",
            "script-src-attr 'none'",
            "style-src 'self' 'nonce-{$nonce}'",
            "style-src-attr 'none'",
            "font-src 'self' data:",
            "img-src 'self' data: blob:",
            "media-src 'self' blob:",
            "worker-src 'self' blob:",
        ]);
    }

    public static function apply(): void
    {
        if (self::$applied) {
            return;
        }

        if (headers_sent($file, $line)) {
            throw new RuntimeException("Cannot apply security headers after output at {$file}:{$line}");
        }

        header('Content-Security-Policy: ' . self::policy());
        self::$applied = true;
    }
}
