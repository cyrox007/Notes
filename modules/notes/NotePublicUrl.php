<?php

declare(strict_types=1);

namespace Modules\Notes;

use Core\Config;
use RuntimeException;

/**
 * Публичные ссылки заметок должны быть абсолютными.
 * Внутренний base_url содержит только путь установки и здесь не подходит.
 */
final class NotePublicUrl
{
    public static function shared(string $token): string
    {
        return self::absolute('/notes/shared/' . rawurlencode($token));
    }

    public static function absolute(string $path): string
    {
        $configured = trim((string) Config::get('SITEURL', ''));
        $parts = parse_url($configured);
        if (!is_array($parts)) {
            throw new RuntimeException('Для публичных ссылок необходимо указать SITEURL с доменом');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('SITEURL должен содержать http(s) и имя домена');
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $origin = $scheme . '://' . $host . $port;
        $envBase = trim((string) (getenv('BASE_PATH') ?: ''), '/');
        $basePath = $envBase !== '' ? $envBase : trim((string) ($parts['path'] ?? ''), '/');
        $prefix = $basePath === '' ? '' : '/' . $basePath;

        return $origin . $prefix . '/' . ltrim($path, '/');
    }
}
