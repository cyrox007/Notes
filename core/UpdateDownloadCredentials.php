<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

require_once __DIR__ . '/UpdatePath.php';
require_once __DIR__ . '/Version.php';

/**
 * Учётные данные одной установки для защищённой загрузки обновлений.
 *
 * Файл всегда хранится вне дерева приложения. Если старый .env содержит
 * небезопасный путь внутри приложения, используется безопасный путь под
 * PRIVATE_STORAGE_PATH.
 */
final class UpdateDownloadCredentials
{
    public function __construct(private array $data)
    {
        if (($data['schema'] ?? null) !== 1
            || !is_string($data['installation_id'] ?? null)
            || preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $data['installation_id']) !== 1
            || !is_string($data['token'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $data['token']) !== 1
            || !is_string($data['base_url'] ?? null)) {
            throw new RuntimeException('Некорректный файл доступа к обновлениям');
        }

        self::validateBaseUrl($data['base_url']);
    }

    public static function validateBaseUrl(string $url): void
    {
        if (preg_match('~^https://[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?:/[A-Za-z0-9_-]+)*/$~D', $url) !== 1
            || !str_contains((string) parse_url($url, PHP_URL_HOST), '.')) {
            throw new RuntimeException('Адрес сервера обновлений должен быть каноническим HTTPS-каталогом');
        }
    }

    public static function accessMode(): string
    {
        $mode = strtolower(trim((string) getenv('UPDATE_ACCESS_MODE')));
        if ($mode === '') {
            return 'auto';
        }
        if (!in_array($mode, ['auto', 'online', 'offline'], true)) {
            throw new RuntimeException('UPDATE_ACCESS_MODE должен быть auto, online или offline');
        }
        return $mode;
    }

    public static function credentialsPath(): string
    {
        $configured = trim((string) getenv('UPDATE_CREDENTIALS_FILE'));
        if ($configured !== '') {
            try {
                self::assertExternalPath($configured);
                return $configured;
            } catch (RuntimeException) {
                // Старые версии позволяли оператору ошибочно указать файл внутри
                // приложения. Для 1.0.2 такой путь безопасно игнорируется.
            }
        }

        return self::defaultCredentialsPath();
    }

    public static function defaultCredentialsPath(): string
    {
        $private = trim((string) getenv('PRIVATE_STORAGE_PATH'));
        if ($private === '') {
            throw new RuntimeException('PRIVATE_STORAGE_PATH не настроен');
        }
        if (!UpdatePath::isAbsolute($private)) {
            throw new RuntimeException('PRIVATE_STORAGE_PATH должен быть абсолютным');
        }

        $resolved = realpath($private);
        if (!is_string($resolved) || !is_dir($resolved) || !is_writable($resolved)) {
            throw new RuntimeException('PRIVATE_STORAGE_PATH недоступен для записи');
        }

        $appRoot = realpath(dirname(__DIR__));
        if (!is_string($appRoot) || UpdatePath::inside($resolved, $appRoot)) {
            throw new RuntimeException('PRIVATE_STORAGE_PATH должен находиться вне дерева приложения');
        }

        return rtrim($resolved, '/\\')
            . DIRECTORY_SEPARATOR . 'update-access'
            . DIRECTORY_SEPARATOR . 'update-access.json';
    }

    public static function fromEnvironment(): ?self
    {
        $mode = self::accessMode();
        if ($mode === 'offline') {
            return null;
        }

        $path = self::credentialsPath();
        if (!is_file($path)) {
            if ($mode === 'auto') {
                return null;
            }
            throw new RuntimeException('Доступ к обновлениям ещё не активирован');
        }

        return self::fromFile($path);
    }

    public static function fromFile(string $path): self
    {
        self::assertExternalPath($path);
        if (!is_file($path) || !is_readable($path) || filesize($path) > 4096) {
            throw new RuntimeException('Файл доступа к обновлениям отсутствует или недоступен');
        }
        if (DIRECTORY_SEPARATOR === '/' && (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('Файл доступа к обновлениям должен иметь права 0600');
        }

        $data = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Некорректный файл доступа к обновлениям');
        }

        return new self($data);
    }

    /** @param array<string,mixed> $data */
    public static function store(array $data): string
    {
        new self($data);

        $path = self::credentialsPath();
        $parent = dirname($path);
        self::ensureDirectory($parent);
        self::assertExternalPath($path);

        $bytes = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        $temporary = $path . '.new-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось сохранить доступ к обновлениям');
        }
        @chmod($temporary, 0600);

        if (is_file($path) && !@unlink($path)) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось заменить старый файл доступа к обновлениям');
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось активировать новый файл доступа к обновлениям');
        }
        @chmod($path, 0600);

        return $path;
    }

    public static function quarantine(): ?string
    {
        $path = self::credentialsPath();
        if (!is_file($path)) {
            return null;
        }

        self::assertExternalPath($path);
        $quarantine = $path . '.rejected-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        if (!@rename($path, $quarantine)) {
            throw new RuntimeException('Не удалось изолировать устаревший доступ к обновлениям');
        }
        @chmod($quarantine, 0600);

        return $quarantine;
    }

    public static function assertExternalPath(string $path): void
    {
        $path = trim($path);
        if ($path === '' || !UpdatePath::isAbsolute($path) || is_link($path)) {
            throw new RuntimeException('Файл доступа к обновлениям должен находиться по абсолютному внешнему пути');
        }

        $parent = realpath(dirname($path));
        $root = realpath(dirname(__DIR__));
        if (!is_string($parent) || !is_string($root) || UpdatePath::inside($parent, $root)) {
            throw new RuntimeException('Файл доступа к обновлениям должен находиться вне дерева приложения');
        }
    }

    private static function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            if (!is_writable($path)) {
                throw new RuntimeException('Каталог доступа к обновлениям недоступен для записи');
            }
            return;
        }

        $parent = dirname($path);
        while (!is_dir($parent) && $parent !== dirname($parent)) {
            $parent = dirname($parent);
        }
        $root = realpath(dirname(__DIR__));
        $resolvedParent = realpath($parent);
        if (!is_string($resolvedParent) || !is_string($root) || UpdatePath::inside($resolvedParent, $root)) {
            throw new RuntimeException('Каталог доступа к обновлениям должен находиться вне дерева приложения');
        }
        if (!is_writable($resolvedParent)) {
            throw new RuntimeException('Нельзя создать каталог доступа к обновлениям');
        }
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Не удалось создать каталог доступа к обновлениям');
        }
        @chmod($path, 0700);
    }

    public function installationId(): string
    {
        return $this->data['installation_id'];
    }

    public function baseUrl(): string
    {
        return $this->data['base_url'];
    }

    public function headersFor(string $url): string
    {
        $base = $this->data['base_url'];
        if (!str_starts_with($url, $base)) {
            throw new RuntimeException('Учётные данные нельзя отправлять за пределы активированного сервера обновлений');
        }

        $relative = substr($url, strlen($base));
        if (preg_match('~^(?:alpha|beta|stable)/[A-Za-z0-9][A-Za-z0-9._+-]*$~D', $relative) !== 1) {
            throw new RuntimeException('Запрос обновления вышел за разрешённые пути сервера');
        }

        return 'Authorization: Bearer ' . $this->data['token'] . "\r\n"
            . 'X-Notes-Installation: ' . $this->data['installation_id'] . "\r\n"
            . 'X-Notes-Version: ' . Version::VERSION . "\r\n"
            . 'X-Notes-Version-Code: ' . Version::VERSION_CODE . "\r\n";
    }
}
