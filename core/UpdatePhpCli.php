<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Находит CLI-бинарник PHP для запуска транзакционного обновлятора из web-процесса.
 *
 * В web-SAPI константа PHP_BINARY может указывать на php-cgi или php-fpm,
 * поэтому для установки обновления используется отдельный CLI-бинарник.
 */
final class UpdatePhpCli
{
    public static function resolve(): string
    {
        $candidates = [];

        $configured = trim((string) (getenv('UPDATE_PHP_BINARY') ?: ''));
        if ($configured !== '') {
            $candidates[] = $configured;
        }

        $bindir = defined('PHP_BINDIR') ? trim((string) PHP_BINDIR) : '';
        if ($bindir !== '') {
            $candidates[] = rtrim($bindir, '/\\') . DIRECTORY_SEPARATOR . 'php.exe';
            $candidates[] = rtrim($bindir, '/\\') . DIRECTORY_SEPARATOR . 'php';
        }

        if (PHP_SAPI === 'cli' && trim((string) PHP_BINARY) !== '') {
            $candidates[] = PHP_BINARY;
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (!self::isAbsolute($candidate) || !is_file($candidate)) {
                continue;
            }
            if (DIRECTORY_SEPARATOR !== '\\' && !is_executable($candidate)) {
                continue;
            }
            return $candidate;
        }

        throw new RuntimeException(
            'Не найден CLI-бинарник PHP для установки обновления. '
            . 'Установите PHP CLI рядом с web-PHP или задайте абсолютный UPDATE_PHP_BINARY.'
        );
    }

    private static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }
        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
