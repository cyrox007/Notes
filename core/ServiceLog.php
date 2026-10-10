<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/Environment.php';

use RuntimeException;
use Throwable;

/**
 * Сервисный журнал приложения для диагностики updater/recovery/runtime.
 *
 * Журнал хранится только во внешнем приватном хранилище и не содержит
 * пользовательский контент. Контекст перед записью очищается от секретов.
 */
final class ServiceLog
{
    public const SCHEMA = 1;
    private const MAX_LINE_BYTES = 16384;
    private const MAX_FILE_BYTES = 5242880;
    private const MAX_CONTEXT_ITEMS = 40;
    private const LEVELS = ['info', 'warning', 'error', 'critical'];
    private const SENSITIVE_KEY = '/(?:^|_)(password|passphrase|secret|token|authorization|cookie|csrf|session|license)(?:$|_)/i';

    private string $path;
    private string $appRoot;

    public function __construct(?string $path = null, ?string $appRoot = null)
    {
        $resolvedRoot = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($resolvedRoot) || !is_dir($resolvedRoot)) {
            throw new RuntimeException('Не удалось определить корень приложения для сервисного журнала');
        }

        $this->appRoot = self::normalize($resolvedRoot);
        $this->path = $this->resolvePath($path);
    }

    public static function registerRuntimeCapture(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if (!is_array($error)) {
                return;
            }

            $type = (int) ($error['type'] ?? 0);
            if (!in_array($type, [
                E_ERROR,
                E_PARSE,
                E_CORE_ERROR,
                E_COMPILE_ERROR,
                E_USER_ERROR,
                E_RECOVERABLE_ERROR,
            ], true)) {
                return;
            }

            self::emit(
                'runtime.fatal',
                'critical',
                'runtime',
                [
                    'php_error_type' => $type,
                    'message' => (string) ($error['message'] ?? ''),
                    'file' => (string) ($error['file'] ?? ''),
                    'line' => (int) ($error['line'] ?? 0),
                    'request_method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
                    'request_path' => self::safeRequestPath(),
                ]
            );
        });
    }

    private static function safeRequestPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) ? mb_substr($path, 0, 512) : '';
    }

    /** @param array<string,mixed> $context */
    public static function emit(
        string $event,
        string $level,
        string $component,
        array $context = []
    ): bool {
        try {
            (new self())->record($event, $level, $component, $context);
            return true;
        } catch (Throwable $e) {
            error_log('Сервисный журнал недоступен: ' . $e->getMessage());
            return false;
        }
    }

    /** @param array<string,mixed> $context */
    public function record(
        string $event,
        string $level,
        string $component,
        array $context = []
    ): void {
        if (preg_match('/^[a-z][a-z0-9_.-]{2,95}$/D', $event) !== 1) {
            throw new RuntimeException('Некорректное имя сервисного события');
        }
        if (!in_array($level, self::LEVELS, true)) {
            throw new RuntimeException('Некорректный уровень сервисного события');
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $component) !== 1) {
            throw new RuntimeException('Некорректный компонент сервисного события');
        }

        $this->rotateIfNeeded();

        $now = time();
        $payload = [
            'schema' => self::SCHEMA,
            'ts' => $now,
            'at' => gmdate('c', $now),
            'event' => $event,
            'level' => $level,
            'component' => $component,
            'context' => $this->sanitizeContext($context),
        ];

        $line = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        if (strlen($line) > self::MAX_LINE_BYTES) {
            throw new RuntimeException('Сервисное событие превышает допустимый размер');
        }

        $handle = @fopen($this->path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Не удалось открыть сервисный журнал');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Не удалось заблокировать сервисный журнал');
            }
            $written = fwrite($handle, $line);
            if ($written !== strlen($line) || !fflush($handle)) {
                throw new RuntimeException('Не удалось полностью записать сервисное событие');
            }
            @chmod($this->path, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return list<array<string,mixed>> */
    public function tail(int $limit = 400): array
    {
        $limit = max(1, min(1000, $limit));
        $events = [];

        foreach ([$this->path . '.1', $this->path] as $path) {
            if (!is_file($path) || is_link($path)) {
                continue;
            }

            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                continue;
            }

            try {
                while (($line = fgets($handle)) !== false) {
                    if (strlen($line) > self::MAX_LINE_BYTES) {
                        continue;
                    }
                    try {
                        $payload = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                    } catch (Throwable) {
                        continue;
                    }
                    if (!is_array($payload) || (int) ($payload['schema'] ?? 0) !== self::SCHEMA) {
                        continue;
                    }
                    $events[] = $payload;
                    if (count($events) > $limit) {
                        array_shift($events);
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        return array_values($events);
    }

    public function path(): string
    {
        return $this->path;
    }

    private function rotateIfNeeded(): void
    {
        clearstatcache(true, $this->path);
        $size = is_file($this->path) ? filesize($this->path) : 0;
        if (!is_int($size) || $size < self::MAX_FILE_BYTES) {
            return;
        }

        $previous = $this->path . '.1';
        if (is_file($previous) && !is_link($previous)) {
            @unlink($previous);
        }
        if (is_file($this->path) && !is_link($this->path)) {
            @rename($this->path, $previous);
            @chmod($previous, 0600);
        }
    }

    private function resolvePath(?string $explicit): string
    {
        $path = trim((string) ($explicit ?? ''));
        if ($path === '') {
            $configured = Environment::get('SERVICE_LOG_PATH');
            $path = is_string($configured) ? trim($configured) : '';
        }
        if ($path === '') {
            $private = Environment::get('PRIVATE_STORAGE_PATH');
            $private = is_string($private) ? trim($private) : '';
            if ($private === '') {
                throw new RuntimeException('PRIVATE_STORAGE_PATH или SERVICE_LOG_PATH не настроен');
            }
            $path = rtrim($private, '/\\')
                . DIRECTORY_SEPARATOR . 'logs'
                . DIRECTORY_SEPARATOR . 'service-events.jsonl';
        }

        if (!self::isAbsolute($path) || is_link($path)) {
            throw new RuntimeException('Сервисный журнал должен находиться по абсолютному безопасному пути');
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            $oldUmask = umask(0077);
            $created = @mkdir($directory, 0700, true);
            umask($oldUmask);
            if (!$created && !is_dir($directory)) {
                throw new RuntimeException('Не удалось создать каталог сервисного журнала');
            }
        }

        if (is_link($directory) || !is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Каталог сервисного журнала небезопасен или недоступен для записи');
        }
        @chmod($directory, 0700);

        $realDirectory = realpath($directory);
        if (!is_string($realDirectory)) {
            throw new RuntimeException('Не удалось разрешить каталог сервисного журнала');
        }
        $realDirectory = self::normalize($realDirectory);
        if (self::inside($realDirectory, $this->appRoot)) {
            throw new RuntimeException('Сервисный журнал должен находиться вне дерева приложения');
        }

        return $realDirectory . DIRECTORY_SEPARATOR . basename($path);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function sanitizeContext(array $context): array
    {
        $safe = [];
        $count = 0;

        foreach ($context as $key => $value) {
            if ($count++ >= self::MAX_CONTEXT_ITEMS) {
                break;
            }

            $name = mb_substr((string) $key, 0, 64);
            if ($name === '') {
                continue;
            }
            if (preg_match(self::SENSITIVE_KEY, $name) === 1) {
                $safe[$name] = '[redacted]';
                continue;
            }

            $safe[$name] = $this->sanitizeValue($value, 0);
        }

        return $safe;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = str_replace($this->appRoot, '[app-root]', $value);
            $private = Environment::get('PRIVATE_STORAGE_PATH');
            if (is_string($private) && trim($private) !== '') {
                $value = str_replace(
                    self::normalize(trim($private)),
                    '[private-storage]',
                    self::normalize($value)
                );
            }

            $value = preg_replace(
                "/(command denied to user)\\s+'[^']+'@'[^']+'/i",
                "$1 '[redacted]'@'[redacted]'",
                $value
            ) ?? $value;
            $value = preg_replace(
                '/([?&](?:token|secret|key|authorization|session|csrf)=)[^&\\s]+/i',
                '$1[redacted]',
                $value
            ) ?? $value;

            return mb_substr($value, 0, 768);
        }

        if (is_array($value) && $depth < 3) {
            $safe = [];
            $count = 0;
            foreach ($value as $key => $child) {
                if ($count++ >= 24) {
                    break;
                }
                $name = mb_substr((string) $key, 0, 64);
                if (preg_match(self::SENSITIVE_KEY, $name) === 1) {
                    $safe[$name] = '[redacted]';
                    continue;
                }
                $safe[$name] = $this->sanitizeValue($child, $depth + 1);
            }
            return $safe;
        }

        return '[' . get_debug_type($value) . ']';
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }

    private static function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:/', $path) === 1) {
            $path = strtolower($path[0]) . substr($path, 1);
        }
        return $path;
    }

    private static function inside(string $path, string $parent): bool
    {
        $path = self::normalize($path);
        $parent = self::normalize($parent);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }
}
