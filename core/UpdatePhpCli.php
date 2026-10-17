<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/Environment.php';
require_once __DIR__ . '/UpdateProcessRunner.php';

use RuntimeException;
use Throwable;

/**
 * Находит CLI-бинарник PHP для запуска транзакционного обновлятора из web-процесса.
 *
 * В web-SAPI константа PHP_BINARY может указывать на php-cgi или php-fpm.
 * Поэтому сначала проверяется явная настройка, затем CLI рядом с фактическим
 * web-бинарником, PHP_BINDIR и абсолютные каталоги из PATH.
 */
final class UpdatePhpCli
{
    private const MINIMUM_PHP_VERSION = '8.3.0';

    public static function resolve(string $minimumVersion = self::MINIMUM_PHP_VERSION): string
    {
        $unsupported = [];

        foreach (self::candidates() as $candidate) {
            if (!self::isAbsolute($candidate) || !is_file($candidate)) {
                continue;
            }
            if (DIRECTORY_SEPARATOR !== '\\' && !is_executable($candidate)) {
                continue;
            }

            $version = self::runtimeVersion($candidate);
            if ($version === null) {
                continue;
            }
            if (version_compare($version, $minimumVersion, '>=')) {
                return $candidate;
            }

            $unsupported[] = $candidate . ' (' . $version . ')';
        }

        if ($unsupported !== []) {
            throw new RuntimeException(
                'Найденный PHP CLI не соответствует требованиям обновления: нужен PHP '
                . $minimumVersion . ' или новее; найдено: ' . implode(', ', $unsupported) . '. '
                . 'Задайте UPDATE_PHP_BINARY на совместимый CLI-бинарник.'
            );
        }

        throw new RuntimeException(
            'Не найден совместимый CLI-бинарник PHP ' . $minimumVersion . '+ для установки обновления. '
            . 'Установите PHP CLI рядом с web-PHP или задайте абсолютный UPDATE_PHP_BINARY.'
        );
    }

    /** @return list<string> */
    private static function candidates(): array
    {
        $candidates = [];

        self::appendCandidate(
            $candidates,
            trim((string) (Environment::get('UPDATE_PHP_BINARY') ?: ''))
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

    private static function runtimeVersion(string $candidate): ?string
    {
        $candidateReal = realpath($candidate);
        $currentReal = PHP_BINARY !== '' ? realpath(PHP_BINARY) : false;
        if (PHP_SAPI === 'cli'
            && is_string($candidateReal)
            && is_string($currentReal)
            && $candidateReal === $currentReal) {
            return PHP_VERSION;
        }

        if (!UpdateProcessRunner::available()) {
            return null;
        }

        try {
            $result = (new UpdateProcessRunner())->run(
                [$candidate, '-r', 'echo PHP_VERSION;'],
                dirname(__DIR__),
                10
            );
        } catch (Throwable) {
            return null;
        }

        if ($result['code'] !== 0) {
            return null;
        }

        $version = trim($result['stdout']);
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $version) === 1
            ? $version
            : null;
    }

    /** @return list<string> */
    private static function pathDirectories(): array
    {
        $path = trim((string) (Environment::get('PATH') ?: ''));
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
