<?php

declare(strict_types=1);

namespace App\Services;

require_once dirname(__DIR__, 3) . '/core/UpdatePath.php';

use Core\UpdatePath;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Снимок пользовательского приватного хранилища. Служебные каталоги
 * резервирования и обновлятора никогда не попадают в копию.
 */
final class AdminBackupFiles
{
    private const EXCLUDED = [
        'admin-backups', 'updates', 'update-continuations',
        'logs', 'cache', 'sessions',
        'workspace-maintenance.json', '.workspace-maintenance.lock',
    ];

    public function __construct(private readonly string $root)
    {
        if (!is_dir($root) || is_link($root) || !is_readable($root)) {
            throw new RuntimeException('Приватное хранилище недоступно');
        }
    }

    /** @return list<array{path:string,size:int,sha256:string}> */
    public function snapshot(string $destination): array
    {
        if (file_exists($destination) || is_link($destination) || !@mkdir($destination, 0700, true)) {
            throw new RuntimeException('Невозможно создать каталог снимка файлов');
        }

        $entries = $this->inventory($this->root, true);
        foreach ($entries as $entry) {
            $relative = $entry['path'];
            $source = $this->root . '/' . $relative;
            $target = $destination . '/' . $relative;
            $this->copyChecked($source, $target, $entry);
        }

        $this->verify($destination, $entries);
        return $entries;
    }

    /** @param list<array{path:string,size:int,sha256:string}> $entries */
    public function verify(string $directory, array $entries): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('Каталог файлов резервной копии повреждён');
        }

        $actual = $this->inventory($directory, false);
        if ($actual !== $entries) {
            throw new RuntimeException('Контрольные суммы или состав файлов резервной копии не совпали');
        }
    }

    /**
     * Подготавливает полную замену пользовательского хранилища, не трогая
     * рабочие файлы. Возвращённый каталог должен находиться в том же томе.
     *
     * @param list<array{path:string,size:int,sha256:string}> $entries
     */
    public function prepareRestore(string $source, array $entries, string $nonce): string
    {
        $this->verify($source, $entries);
        $stage = $this->root . '/.admin-restore-stage-' . $nonce;
        if (file_exists($stage) || is_link($stage) || !@mkdir($stage, 0700)) {
            throw new RuntimeException('Не удалось подготовить восстановление файлов');
        }

        try {
            foreach ($entries as $entry) {
                $this->copyChecked($source . '/' . $entry['path'], $stage . '/' . $entry['path'], $entry);
            }
            $this->verify($stage, $entries);
        } catch (\Throwable $error) {
            UpdatePath::removeTree($stage);
            throw $error;
        }

        return $stage;
    }

    /**
     * Вызывает атомарный rename для каждого верхнего каталога/файла.
     * При сбое не удаляет защитную точку: вызывающий код держит обслуживание.
     *
     * @param list<array{path:string,size:int,sha256:string}> $entries
     */
    public function applyPrepared(string $stage, array $entries, string $nonce): void
    {
        $this->verify($stage, $entries);
        $previous = $this->root . '/.admin-restore-previous-' . $nonce;
        if (file_exists($previous) || is_link($previous) || !@mkdir($previous, 0700)) {
            throw new RuntimeException('Не удалось зарезервировать предыдущие файлы');
        }

        $names = scandir($this->root);
        if (!is_array($names)) {
            throw new RuntimeException('Невозможно просмотреть приватное хранилище');
        }

        foreach ($names as $name) {
            if ($this->excluded($name) || $name === '.' || $name === '..') {
                continue;
            }
            $source = $this->root . '/' . $name;
            if (is_link($source) || !@rename($source, $previous . '/' . $name)) {
                throw new RuntimeException('Не удалось сохранить прежние файлы: ' . $name);
            }
        }

        $staged = scandir($stage);
        if (!is_array($staged)) {
            throw new RuntimeException('Невозможно прочитать подготовленные файлы');
        }
        foreach ($staged as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (!UpdatePath::safeTopLevel($name) || !@rename($stage . '/' . $name, $this->root . '/' . $name)) {
                throw new RuntimeException('Не удалось установить восстановленные файлы: ' . $name);
            }
        }
        $this->verifyLive($entries);
        UpdatePath::removeTree($stage);
        UpdatePath::removeTree($previous);
    }

    /** @param list<array{path:string,size:int,sha256:string}> $entries */
    public function verifyLive(array $entries): void
    {
        if ($this->inventory($this->root, true) !== $entries) {
            throw new RuntimeException('Файлы после восстановления не совпадают с проверенной копией');
        }
    }

    /** @return list<array{path:string,size:int,sha256:string}> */
    private function inventory(string $directory, bool $excludeService): array
    {
        $entries = [];
        $source = new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $source,
            function (\SplFileInfo $item) use ($directory, $excludeService): bool {
                if (!$excludeService) return true;
                $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($directory) + 1));
                $top = explode('/', $relative, 2)[0];
                return !$this->excluded($top);
            }
        );
        $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $item) {
            $absolute = $item->getPathname();
            $relative = substr($absolute, strlen($directory) + 1);
            $relative = str_replace('\\', '/', $relative);
            if (!UpdatePath::safeRelative($relative)) {
                throw new RuntimeException('Недопустимый путь в файловом хранилище');
            }
            $top = explode('/', $relative, 2)[0];
            if ($excludeService && $this->excluded($top)) {
                continue;
            }
            if ($item->isLink() || !$item->isFile() || !$item->isReadable()) {
                throw new RuntimeException('В хранилище найден небезопасный файл: ' . $relative);
            }
            $size = $item->getSize();
            $sha = hash_file('sha256', $absolute);
            if (!is_int($size) || !is_string($sha)) {
                throw new RuntimeException('Не удалось проверить файл: ' . $relative);
            }
            $entries[] = ['path' => $relative, 'size' => $size, 'sha256' => $sha];
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        return $entries;
    }

    private function excluded(string $name): bool
    {
        return in_array($name, self::EXCLUDED, true)
            || str_starts_with($name, '.admin-restore-');
    }

    /** @param array{path:string,size:int,sha256:string} $entry */
    private function copyChecked(string $source, string $target, array $entry): void
    {
        if (is_link($source) || !is_file($source)) {
            throw new RuntimeException('Источник файла повреждён: ' . $entry['path']);
        }
        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('Не удалось создать каталог файловой копии');
        }
        $in = @fopen($source, 'rb');
        $out = @fopen($target, 'xb');
        if ($in === false || $out === false) {
            if (is_resource($in)) fclose($in);
            if (is_resource($out)) fclose($out);
            throw new RuntimeException('Не удалось открыть файл для копирования');
        }
        try {
            $copied = stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
        @chmod($target, 0600);
        $sha = hash_file('sha256', $target);
        if ($copied !== $entry['size'] || !is_string($sha) || !hash_equals($entry['sha256'], $sha)) {
            throw new RuntimeException('Не совпала контрольная сумма: ' . $entry['path']);
        }
    }
}
