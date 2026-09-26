<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

require_once __DIR__ . '/UpdatePath.php';

/**
 * Низкоуровневая пофайловая граница изменения live-кода.
 *
 * Каталоги верхнего уровня никогда не переименовываются. Каждый файл
 * заменяется через временный файл в том же каталоге, поэтому Windows не
 * должен освобождать целиком bin/core/modules для обновления.
 */
final class UpdateFileMutator
{
    private const PRESERVED_RELATIVE_PREFIXES = [
        'tools/vendor-license/',
        'tools/vendor-update/',
    ];

    /** @param list<string> $preservedRoots */
    public function __construct(
        private readonly string $appRoot,
        private readonly array $preservedRoots
    ) {}

    /**
     * @return array<string,array{size:int,sha256:string}>
     */
    public function releaseFiles(): array
    {
        $files = [];
        $this->scanDirectory($this->appRoot, '', $files);
        ksort($files, SORT_STRING);
        return $files;
    }

    public function replaceVerified(
        string $source,
        string $relative,
        int $expectedSize,
        string $expectedSha256,
        int $mode,
        string $transactionId
    ): void {
        $relative = $this->validateRelative($relative);
        $this->validateTransactionId($transactionId);

        if (!is_file($source) || is_link($source) || !is_readable($source)) {
            throw new RuntimeException('Источник updater-файла отсутствует или небезопасен: ' . $relative);
        }

        $sourceSize = filesize($source);
        $sourceSha = hash_file('sha256', $source);
        if (
            !is_int($sourceSize)
            || $sourceSize !== $expectedSize
            || !is_string($sourceSha)
            || !hash_equals($expectedSha256, $sourceSha)
        ) {
            throw new RuntimeException('Источник updater-файла не прошёл SHA-256: ' . $relative);
        }

        $target = $this->appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $this->prepareTargetParent($relative);

        if (is_link($target)) {
            throw new RuntimeException('Live updater-файл не должен быть symlink: ' . $relative);
        }
        if (is_dir($target)) {
            $items = scandir($target);
            if (!is_array($items) || array_diff($items, ['.', '..']) !== []) {
                throw new RuntimeException('Каталог блокирует запись updater-файла: ' . $relative);
            }
            if (!@rmdir($target)) {
                throw new RuntimeException('Не удалось удалить пустой каталог перед записью файла: ' . $relative);
            }
        } elseif (file_exists($target) && !is_file($target)) {
            throw new RuntimeException('Live updater-путь имеет неподдерживаемый тип: ' . $relative);
        }

        $parent = dirname($target);
        $temporary = $parent
            . DIRECTORY_SEPARATOR
            . '.'
            . basename($target)
            . '.update-new-'
            . substr(hash('sha256', $transactionId . ':' . $relative), 0, 12)
            . '-'
            . bin2hex(random_bytes(4));

        $this->copyToNewFile($source, $temporary, $expectedSize, $expectedSha256, $mode);

        if (is_file($target)) {
            // На POSIX rename(temp, target) даёт атомарную замену. Если конкретная
            // Windows-среда не разрешает замену существующего файла, используем
            // короткий file-level quarantine, но никогда не двигаем каталог.
            if (!@rename($temporary, $target)) {
                $old = $parent
                    . DIRECTORY_SEPARATOR
                    . '.'
                    . basename($target)
                    . '.update-old-'
                    . substr(hash('sha256', $transactionId . ':' . $relative), 0, 12)
                    . '-'
                    . bin2hex(random_bytes(4));

                if (!@rename($target, $old)) {
                    @unlink($temporary);
                    throw new RuntimeException('Не удалось освободить live updater-файл: ' . $relative);
                }

                if (!@rename($temporary, $target)) {
                    @rename($old, $target);
                    @unlink($temporary);
                    throw new RuntimeException('Не удалось активировать новый updater-файл: ' . $relative);
                }

                @unlink($old);
            }
        } elseif (!@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось опубликовать новый updater-файл: ' . $relative);
        }

        @chmod($target, $mode & 0777);
        $actualSize = filesize($target);
        $actualSha = hash_file('sha256', $target);
        if (
            !is_int($actualSize)
            || $actualSize !== $expectedSize
            || !is_string($actualSha)
            || !hash_equals($expectedSha256, $actualSha)
        ) {
            throw new RuntimeException('Live updater-файл не прошёл проверку после замены: ' . $relative);
        }
    }

    public function delete(string $relative, string $transactionId): void
    {
        $relative = $this->validateRelative($relative);
        $this->validateTransactionId($transactionId);

        $target = $this->appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!file_exists($target) && !is_link($target)) {
            $this->pruneParents($relative);
            return;
        }
        if (is_link($target) || !is_file($target)) {
            throw new RuntimeException('Удаляемый updater-путь не является обычным файлом: ' . $relative);
        }

        $parent = dirname($target);
        $quarantine = $parent
            . DIRECTORY_SEPARATOR
            . '.'
            . basename($target)
            . '.update-delete-'
            . substr(hash('sha256', $transactionId . ':' . $relative), 0, 12)
            . '-'
            . bin2hex(random_bytes(4));

        if (!@rename($target, $quarantine)) {
            throw new RuntimeException('Не удалось изолировать удаляемый updater-файл: ' . $relative);
        }
        if (!@unlink($quarantine)) {
            throw new RuntimeException('Не удалось удалить изолированный updater-файл: ' . $relative);
        }

        $this->pruneParents($relative);
    }

    private function copyToNewFile(
        string $source,
        string $target,
        int $expectedSize,
        string $expectedSha256,
        int $mode
    ): void {
        $input = @fopen($source, 'rb');
        $output = @fopen($target, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($target);
            throw new RuntimeException('Не удалось создать временный updater-файл');
        }

        try {
            $copied = stream_copy_to_stream($input, $output);
            if ($copied !== $expectedSize || !fflush($output)) {
                throw new RuntimeException('Временный updater-файл записан не полностью');
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        @chmod($target, $mode & 0777);
        $sha = hash_file('sha256', $target);
        if (!is_string($sha) || !hash_equals($expectedSha256, $sha)) {
            @unlink($target);
            throw new RuntimeException('SHA-256 временного updater-файла не совпадает');
        }
    }

    private function prepareTargetParent(string $relative): void
    {
        $parentRelative = str_replace('\\', '/', dirname($relative));
        if ($parentRelative === '.' || $parentRelative === '') {
            return;
        }

        $current = $this->appRoot;
        foreach (explode('/', $parentRelative) as $part) {
            if ($part === '') {
                continue;
            }

            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) {
                throw new RuntimeException('Symlink блокирует каталог updater-файла: ' . $relative);
            }
            if (is_dir($current)) {
                continue;
            }
            if (file_exists($current)) {
                throw new RuntimeException('Файл блокирует каталог updater-файла: ' . $relative);
            }

            $oldUmask = umask(0022);
            $created = @mkdir($current, 0755, false);
            umask($oldUmask);
            if (!$created && !is_dir($current)) {
                throw new RuntimeException('Не удалось создать каталог updater-файла: ' . $relative);
            }
            @chmod($current, 0755);
        }
    }

    private function pruneParents(string $relative): void
    {
        $parent = str_replace('\\', '/', dirname($relative));

        while ($parent !== '' && $parent !== '.') {
            $top = explode('/', $parent, 2)[0];
            if ($this->isPreservedRoot($top)) {
                return;
            }

            $absolute = $this->appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $parent);
            if (!is_dir($absolute) || is_link($absolute)) {
                return;
            }

            $items = scandir($absolute);
            if (!is_array($items) || array_diff($items, ['.', '..']) !== []) {
                return;
            }
            if (!@rmdir($absolute)) {
                return;
            }

            $parent = str_replace('\\', '/', dirname($parent));
        }
    }

    /**
     * @param array<string,array{size:int,sha256:string}> $files
     */
    private function scanDirectory(string $directory, string $relative, array &$files): void
    {
        $items = scandir($directory);
        if (!is_array($items)) {
            throw new RuntimeException('Не удалось прочитать live-tree во время updater-проверки');
        }

        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $childRelative = $relative === '' ? $name : $relative . '/' . $name;
            if ($this->isPreservedRelative($childRelative)) {
                continue;
            }
            if (!UpdatePath::safeRelative($childRelative)) {
                throw new RuntimeException('Live-tree содержит небезопасный путь: ' . $childRelative);
            }

            $absolute = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_link($absolute)) {
                throw new RuntimeException('Live-tree содержит symlink: ' . $childRelative);
            }
            if (is_dir($absolute)) {
                $this->scanDirectory($absolute, $childRelative, $files);
                continue;
            }
            if (!is_file($absolute)) {
                throw new RuntimeException('Live-tree содержит неподдерживаемый объект: ' . $childRelative);
            }

            $size = filesize($absolute);
            $sha = hash_file('sha256', $absolute);
            if (!is_int($size) || !is_string($sha)) {
                throw new RuntimeException('Не удалось проверить live-файл: ' . $childRelative);
            }

            $files[$childRelative] = ['size' => $size, 'sha256' => $sha];
        }
    }

    private function validateRelative(string $relative): string
    {
        $relative = str_replace('\\', '/', trim($relative));
        if (!UpdatePath::safeRelative($relative) || $this->isPreservedRelative($relative)) {
            throw new RuntimeException('Updater отказался менять защищённый путь: ' . $relative);
        }
        return $relative;
    }

    private function isPreservedRelative(string $relative): bool
    {
        if ($relative === '.env' || str_starts_with($relative, '.env.')) {
            return true;
        }

        $top = explode('/', $relative, 2)[0];
        if ($this->isPreservedRoot($top)) {
            return true;
        }

        foreach (self::PRESERVED_RELATIVE_PREFIXES as $prefix) {
            if ($relative === rtrim($prefix, '/') || str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isPreservedRoot(string $name): bool
    {
        return in_array($name, $this->preservedRoots, true);
    }

    private function validateTransactionId(string $transactionId): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/D', trim($transactionId)) !== 1) {
            throw new RuntimeException('Некорректный идентификатор updater-транзакции');
        }
    }
}
