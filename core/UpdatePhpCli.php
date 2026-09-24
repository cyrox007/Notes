<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Находит CLI-бинарник PHP для запуска транзакционного обновлятора из web-процесса.
 *
 * В web-SAPI константа PHP_BINARY может указывать на php-cgi или php-fpm.
 * Поэтому сначала проверяется явная настройка, затем CLI рядом с фактическим
 * web-бинарником, PHP_BINDIR и абсолютные каталоги из PATH.
 */
final class UpdatePhpCli
{
    public static function resolve(): string
    {
        foreach (self::candidates() as $candidate) {
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

    /** @return list<string> */
    private static function candidates(): array
    {
        $candidates = [];

        self::appendCandidate(
            $candidates,
            trim((string) (getenv('UPDATE_PHP_BINARY') ?: ''))
        );

        $phpBinary = trim((string) PHP_BINARY);
        if ($phpBinary !== '') {
            self::appendCandidate(
                $candidates,
                dirname($phpBinary) . DIRECTORY_SEPARATOR . self::cliBinaryName()
            );
            if (PHP_SAPI === 'cli') {
                self::appendCandidate($candidates, $phpBinary);
            }
        }

        $bindir = defined('PHP_BINDIR') ? trim((string) PHP_BINDIR) : '';
        if ($bindir !== '') {
            self::appendCandidate(
                $candidates,
                rtrim($bindir, '/\\') . DIRECTORY_SEPARATOR . self::cliBinaryName()
            );
        }

        foreach (self::pathDirectories() as $directory) {
            self::appendCandidate(
                $candidates,
                $directory . DIRECTORY_SEPARATOR . self::cliBinaryName()
            );
        }

        return $candidates;
    }

    /** @param list<string> $candidates */
    private static function appendCandidate(array &$candidates, string $candidate): void
    {
        $candidate = trim($candidate);
        if ($candidate === '' || in_array($candidate, $candidates, true)) {
            return;
        }
        $candidates[] = $candidate;
    }

    /** @return list<string> */
    private static function pathDirectories(): array
    {
        $path = trim((string) (getenv('PATH') ?: ''));
        if ($path === '') {
            return [];
        }

        $separator = PHP_OS_FAMILY === 'Windows' ? ';' : PATH_SEPARATOR;
        $directories = [];
        foreach (explode($separator, $path) as $directory) {
            $directory = trim($directory, " \t\n\r\0\x0B\"");
            if ($directory === '' || !self::isAbsolute($directory)) {
                continue;
            }
            $directory = rtrim($directory, '/\\');
            if ($directory === '' || in_array($directory, $directories, true)) {
                continue;
            }
            $directories[] = $directory;
        }

        return $directories;
    }

    private static function cliBinaryName(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
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
