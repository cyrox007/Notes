<?php

declare(strict_types=1);

namespace App\Services\Files;

use RuntimeException;

/**
 * Защищает физическое файловое пространство от размещения в корне сайта.
 * Символические ссылки разрешаются перед сравнением путей.
 */
final class FileSpaceStorageGuard
{
    public static function assertOutsideWebRoot(string $storageBase): void
    {
        $base = realpath($storageBase);
        if ($base === false || !is_dir($base)) {
            throw new RuntimeException('Приватное хранилище не найдено');
        }

        $sitePath = defined('SITEPATH') ? (string) SITEPATH : dirname(__DIR__, 3);
        $roots = [$sitePath, (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')];
        foreach ($roots as $candidate) {
            if (trim($candidate) === '') {
                continue;
            }
            $webRoot = realpath($candidate);
            if ($webRoot === false || !is_dir($webRoot)) {
                continue;
            }
            // Не допускаются как вложенный каталог сайта, так и общий
            // родитель сайта: приватное хранилище должно быть отдельным деревом.
            if (self::overlaps($base, $webRoot)) {
                throw new RuntimeException(
                    'PRIVATE_STORAGE_PATH должен располагаться вне каталогов сайта'
                );
            }
        }
    }

    private static function overlaps(string $storage, string $webRoot): bool
    {
        $storage = rtrim($storage, '/\\');
        $webRoot = rtrim($webRoot, '/\\');
        if (DIRECTORY_SEPARATOR === '\\') {
            $storage = strtolower($storage);
            $webRoot = strtolower($webRoot);
        }

        return $storage === $webRoot
            || str_starts_with($storage, $webRoot . DIRECTORY_SEPARATOR)
            || str_starts_with($webRoot, $storage . DIRECTORY_SEPARATOR);
    }
}
