<?php

declare(strict_types=1);

namespace Core;

/**
 * Shared filesystem/path primitives for updater components.
 *
 * The updater previously carried near-identical copies of these rules in every
 * staging, backup, transaction and live-switch class. Keeping them here makes
 * the path trust boundary consistent without changing each public API.
 */
final class UpdatePath
{
    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    public static function normalize(string $path, bool $canonicalizeDrive = true): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (
            $canonicalizeDrive
            && PHP_OS_FAMILY === 'Windows'
            && preg_match('/^[A-Za-z]:/', $path) === 1
        ) {
            $path = strtolower($path[0]) . substr($path, 1);
        }
        return $path;
    }

    public static function inside(string $path, string $parent): bool
    {
        $path = self::normalize($path);
        $parent = self::normalize($parent);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }

        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }

    public static function safeRelative(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\') || str_contains($path, "\0") || str_starts_with($path, '/')) {
            return false;
        }

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }

        return true;
    }

    public static function safeTopLevel(string $name): bool
    {
        return $name !== ''
            && $name !== '.'
            && $name !== '..'
            && !str_contains($name, '/')
            && !str_contains($name, '\\')
            && !str_contains($name, "\0");
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }

        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
