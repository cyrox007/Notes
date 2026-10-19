<?php

declare(strict_types=1);

namespace Core;

/**
 * Проверка допустимых путей и удаление управляемых файлов.
 * Единая политика для модулей и обезличивания аккаунтов.
 */
final class RetentionFileCleanup
{
    public function __construct(
        private readonly string $appRoot,
        private readonly string $privateRoot
    ) {
    }

    /** @param list<string> $paths @param array<string,mixed> $result */
    public function removePaths(array $paths, array &$result): bool
    {
        $ok = true;
        foreach (array_values(array_unique($paths)) as $storedPath) {
            $status = $this->removeManagedFile($storedPath);
            if ($status === 'deleted') {
                $result['files_deleted']++;
            } elseif ($status === 'missing') {
                $result['files_missing']++;
            } elseif ($status === 'blocked') {
                $result['files_blocked']++;
                $ok = false;
            } else {
                $result['files_failed']++;
                $ok = false;
            }
        }
        return $ok;
    }

    private function removeManagedFile(string $storedPath): string
    {
        $storedPath = trim($storedPath);
        if ($storedPath === '') {
            return 'missing';
        }

        $candidate = $this->isAbsolutePath($storedPath)
            ? $storedPath
            : $this->appRoot . DIRECTORY_SEPARATOR . ltrim($storedPath, '/\\');

        $allowedRoots = [
            $this->privateRoot,
            $this->appRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'file_manager',
            $this->appRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'notes',
            $this->appRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'messenger',
            $this->appRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'users',
        ];

        $lexicalAllowed = false;
        foreach ($allowedRoots as $root) {
            if ($this->isPathWithin($candidate, $root)) {
                $lexicalAllowed = true;
                break;
            }
        }
        if (!$lexicalAllowed) {
            return 'blocked';
        }

        if (!file_exists($candidate)) {
            return 'missing';
        }
        if (is_link($candidate) || !is_file($candidate)) {
            return 'blocked';
        }

        $real = realpath($candidate);
        if (!is_string($real)) {
            return 'failed';
        }
        $realAllowed = false;
        foreach ($allowedRoots as $root) {
            $realRoot = realpath($root);
            if (is_string($realRoot) && $this->isPathWithin($real, $realRoot)) {
                $realAllowed = true;
                break;
            }
        }
        if (!$realAllowed) {
            return 'blocked';
        }

        return @unlink($real) ? 'deleted' : 'failed';
    }


    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }

    private function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $parts = [];
        foreach (explode('/', $normalized) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        $prefix = str_starts_with($normalized, '/') ? '/' : '';
        return $prefix . implode('/', $parts);
    }

    private function isPathWithin(string $candidate, string $root): bool
    {
        $candidate = rtrim($this->normalizePath($candidate), '/');
        $root = rtrim($this->normalizePath($root), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $candidate = strtolower($candidate);
            $root = strtolower($root);
        }
        return $candidate === $root || str_starts_with($candidate . '/', $root . '/');
    }
}
