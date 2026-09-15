<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class Environment
{
    /**
     * Load a Workspace Organizer .env file into getenv(), $_ENV and $_SERVER.
     * Existing process/server variables are immutable and always win.
     */
    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException(
                'Environment configuration file (.env) not found or unreadable. '
                . 'Create a .env file in the project root or run the installer.'
            );
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('Unable to read environment configuration file: ' . $file);
        }

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $lines = preg_split('/\r\n|\n|\r/', $contents);
        if ($lines === false) {
            throw new RuntimeException('Unable to parse environment configuration file: ' . $file);
        }

        foreach ($lines as $index => $line) {
            self::loadLine($line, $index + 1, $file);
        }
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
            throw self::syntaxError($file, $lineNumber, 'expected KEY=VALUE');
        }

        $key = $matches[1];
        $value = self::parseValue($matches[2], $file, $lineNumber);

        if (self::isDefined($key)) {
            return;
        }

        if (!putenv($key . '=' . $value)) {
            throw new RuntimeException("Unable to publish environment variable {$key}.");
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
                throw self::syntaxError($file, $lineNumber, 'unterminated or invalid single-quoted value');
            }

            return self::decodeSingleQuoted($matches[1]);
        }

        if ($first === '"') {
            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"\s*(?:#.*)?$/s', $raw, $matches) !== 1) {
                throw self::syntaxError($file, $lineNumber, 'unterminated or invalid double-quoted value');
            }

            return self::expandVariables(self::decodeDoubleQuoted($matches[1]));
        }

        $value = preg_replace('/\s+#.*$/', '', $raw);
        if ($value === null) {
            throw self::syntaxError($file, $lineNumber, 'invalid unquoted value');
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
                // Keep escaped dollars protected until variable expansion has
                // finished. Otherwise "\${NAME}" would incorrectly expand.
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
            throw new RuntimeException('Unable to expand environment variable reference.');
        }

        // Unescape protected dollars after expansion. This covers both ${VAR}
        // literals and other escaped dollar sequences inside double quotes.
        return str_replace('\\$', '$', $expanded);
    }

    private static function isDefined(string $key): bool
    {
        return getenv($key) !== false
            || array_key_exists($key, $_ENV)
            || array_key_exists($key, $_SERVER);
    }

    private static function syntaxError(string $file, int $lineNumber, string $reason): RuntimeException
    {
        return new RuntimeException(
            sprintf('Invalid environment configuration in %s on line %d: %s.', $file, $lineNumber, $reason)
        );
    }
}
