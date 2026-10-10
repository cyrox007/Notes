<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/HostingCompatibility.php';

use RuntimeException;

final class Environment
{
    /**
     * Загружает .env в getenv(), $_ENV и $_SERVER.
     * Уже заданные переменные процесса имеют приоритет и не перезаписываются.
     */
    public static function load(string $file): void
    {
        if (!HostingCompatibility::processEnvironmentAvailable()) {
            throw new RuntimeException(
                'PHP-функции getenv/putenv недоступны. Текущая конфигурация хостинга несовместима '
                . 'с загрузчиком окружения Workspace Organizer.'
            );
        }

        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException(
                'Файл окружения .env не найден или недоступен для чтения. '
                . 'Создайте .env в корне проекта или запустите установщик.'
            );
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('Не удалось прочитать файл окружения: ' . $file);
        }

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $lines = preg_split('/\r\n|\n|\r/', $contents);
        if ($lines === false) {
            throw new RuntimeException('Не удалось разобрать файл окружения: ' . $file);
        }

        foreach ($lines as $index => $line) {
            self::loadLine($line, $index + 1, $file);
        }
    }

    /** Request-local fallback when Apache loses process environment values. */
    public static function get(string $name): string|false
    {
        $value = getenv($name);
        if ($value !== false) return $value;
        if (array_key_exists($name, $_ENV)) return (string) $_ENV[$name];
        if (array_key_exists($name, $_SERVER)) return (string) $_SERVER[$name];
        return false;
    }

    private static function loadLine(string $line, int $lineNumber, string $file): void
    {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            return;
        }

        if (str_starts_with($trimmed, 'export ')) {
            $trimmed = ltrim(substr($trimmed, 7));
        }

        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $trimmed, $matches) !== 1) {
            throw self::syntaxError($file, $lineNumber, 'ожидалось KEY=VALUE');
        }

        $key = $matches[1];
        $value = self::parseValue($matches[2], $file, $lineNumber);

        // Apache on Windows may expose a variable only through getenv().
        // Capture it in request-local arrays too, before another request ends.
        $existing = getenv($key);
        if ($existing !== false) {
            $value = $existing;
        } elseif (array_key_exists($key, $_ENV)) {
            $value = (string) $_ENV[$key];
        } elseif (array_key_exists($key, $_SERVER)) {
            $value = (string) $_SERVER[$key];
        }

        if (!putenv($key . '=' . $value)) {
            throw new RuntimeException("Не удалось опубликовать переменную окружения {$key}.");
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private static function parseValue(string $raw, string $file, int $lineNumber): string
    {
        if ($raw === '') {
            return '';
        }

        $first = $raw[0];
        if ($first === "'") {
            if (preg_match("/^'((?:[^'\\\\]|\\\\.)*)'\\s*(?:#.*)?$/s", $raw, $matches) !== 1) {
                throw self::syntaxError($file, $lineNumber, 'незакрытое или некорректное значение в одинарных кавычках');
            }

            return self::decodeSingleQuoted($matches[1]);
        }

        if ($first === '"') {
            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"\s*(?:#.*)?$/s', $raw, $matches) !== 1) {
                throw self::syntaxError($file, $lineNumber, 'незакрытое или некорректное значение в двойных кавычках');
            }

            return self::expandVariables(self::decodeDoubleQuoted($matches[1]));
        }

        $value = preg_replace('/\s+#.*$/', '', $raw);
        if ($value === null) {
            throw self::syntaxError($file, $lineNumber, 'некорректное значение без кавычек');
        }

        return self::expandVariables(rtrim($value));
    }

    private static function decodeSingleQuoted(string $value): string
    {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], $value);
    }

    private static function decodeDoubleQuoted(string $value): string
    {
        $result = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] !== '\\' || $i + 1 >= $length) {
                $result .= $value[$i];
                continue;
            }

            $next = $value[++$i];
            $result .= match ($next) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                '"' => '"',
                '\\' => '\\',
                // Экранированный доллар сохраняем до завершения подстановки,
                // чтобы литерал "\${NAME}" не раскрывался как переменная.
                '$' => '\\$',
                default => '\\' . $next,
            };
        }

        return $result;
    }

    private static function expandVariables(string $value): string
    {
        $expanded = preg_replace_callback(
            '/(?<!\\\\)\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $matches): string {
                $value = getenv($matches[1]);
                if ($value !== false) {
                    return $value;
                }

                if (array_key_exists($matches[1], $_ENV)) {
                    return (string) $_ENV[$matches[1]];
                }

                if (array_key_exists($matches[1], $_SERVER)) {
                    return (string) $_SERVER[$matches[1]];
                }

                return '';
            },
            $value
        );

        if ($expanded === null) {
            throw new RuntimeException('Не удалось раскрыть ссылку на переменную окружения.');
        }

        // После подстановки возвращаем экранированные доллары. Это сохраняет
        // литералы вида ${VAR} и другие последовательности с \$ внутри кавычек.
        return str_replace('\\$', '$', $expanded);
    }

    private static function syntaxError(string $file, int $lineNumber, string $reason): RuntimeException
    {
        return new RuntimeException(
            sprintf('Некорректная конфигурация окружения в %s, строка %d: %s.', $file, $lineNumber, $reason)
        );
    }
}
