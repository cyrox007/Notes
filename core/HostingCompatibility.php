<?php

declare(strict_types=1);

namespace Core;

final class HostingCompatibility
{
    public const MIN_MYSQL_VERSION = '8.0.0';
    public const MIN_MARIADB_VERSION = '10.5.0';
    public const RECOMMENDED_MEMORY_BYTES = 128 * 1024 * 1024;

    public static function functionAvailable(string $name): bool
    {
        if (!function_exists($name)) {
            return false;
        }

        // Начиная с PHP 8 отключённые функции обычно уже не считаются
        // существующими. Список disable_functions остаётся дополнительной
        // проверкой, но отсутствие ini_get не должно само вызывать fatal error.
        if ($name === 'ini_get' || !function_exists('ini_get')) {
            return true;
        }

        $disabled = array_filter(
            array_map('trim', explode(',', (string) ini_get('disable_functions')))
        );

        return !in_array($name, $disabled, true);
    }

    public static function iniValue(string $name): string
    {
        return self::functionAvailable('ini_get')
            ? (string) ini_get($name)
            : '';
    }

    public static function processEnvironmentAvailable(): bool
    {
        return self::functionAvailable('getenv')
            && self::functionAvailable('putenv');
    }

    /**
     * Проверяет только локальные PHP-предпосылки.
     * Сетевой фильтр провайдера может отдельно запрещать исходящий TCP/443.
     *
     * @return array{ok:bool,missing:list<string>}
     */
    public static function outboundHttpsPrerequisites(): array
    {
        $missing = [];

        if (!extension_loaded('openssl')) {
            $missing[] = 'openssl';
        }
        if (!self::functionAvailable('stream_socket_client')) {
            $missing[] = 'stream_socket_client';
        }
        if (!self::functionAvailable('stream_context_create')) {
            $missing[] = 'stream_context_create';
        }
        if (!self::functionAvailable('dns_get_record') && !self::functionAvailable('gethostbynamel')) {
            $missing[] = 'dns_get_record|gethostbynamel';
        }

        return ['ok' => $missing === [], 'missing' => $missing];
    }

    /**
     * Возвращает лимит памяти в байтах. null означает отсутствие конечного лимита
     * либо невозможность надёжно прочитать значение.
     */
    public static function memoryLimitBytes(): ?int
    {
        $raw = trim(self::iniValue('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $bytes = self::iniBytes($raw);
        return $bytes > 0 ? $bytes : null;
    }

    public static function iniBytes(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (float) $raw;
        $multiplier = match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return (int) floor($value * $multiplier);
    }

    /**
     * @return array{supported:bool,engine:string,version:string,raw:string,message:string}
     */
    public static function databaseServerSupport(string $rawVersion): array
    {
        $rawVersion = trim($rawVersion);
        $isMariaDb = stripos($rawVersion, 'mariadb') !== false;
        $engine = $isMariaDb ? 'MariaDB' : 'MySQL';

        $version = '';
        $pattern = $isMariaDb
            ? '/(?:^|[- ])(\d+\.\d+(?:\.\d+)?)(?=-MariaDB)/i'
            : '/(\d+\.\d+(?:\.\d+)?)/';
        if (preg_match($pattern, $rawVersion, $matches) !== 1 && $isMariaDb) {
            preg_match('/(\d+\.\d+(?:\.\d+)?)/', $rawVersion, $matches);
        }
        if (isset($matches[1])) {
            $version = (string) $matches[1];
            if (substr_count($version, '.') === 1) {
                $version .= '.0';
            }
        }

        $minimum = $isMariaDb ? self::MIN_MARIADB_VERSION : self::MIN_MYSQL_VERSION;
        $supported = $version !== '' && version_compare($version, $minimum, '>=');

        return [
            'supported' => $supported,
            'engine' => $engine,
            'version' => $version,
            'raw' => $rawVersion,
            'message' => $supported
                ? $engine . ' ' . $version
                : sprintf(
                    'Требуется MySQL %s+ или MariaDB %s+; сервер сообщил: %s',
                    self::MIN_MYSQL_VERSION,
                    self::MIN_MARIADB_VERSION,
                    $rawVersion !== '' ? $rawVersion : 'неизвестная версия'
                ),
        ];
    }

    public static function freeDiskBytes(string $path): ?int
    {
        if (!self::functionAvailable('disk_free_space')) {
            return null;
        }

        $probe = $path;
        while ($probe !== '' && !file_exists($probe) && dirname($probe) !== $probe) {
            $probe = dirname($probe);
        }
        if ($probe === '' || !is_dir($probe)) {
            return null;
        }

        $free = @disk_free_space($probe);
        return is_float($free) || is_int($free) ? max(0, (int) $free) : null;
    }
}
