<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/UpdatePath.php';

/**
 * Единое определение внешнего private storage для установщика и обновлятора.
 *
 * Обычный пользователь не должен искать путь вручную. Приоритет:
 * 1) уже настроенный безопасный PRIVATE_STORAGE_PATH;
 * 2) исторический sibling-каталог, который создавал установщик;
 * 3) новый детерминированный sibling-каталог рядом с приложением;
 * 4) такой же каталог в HOME, если sibling недоступен.
 */
final class PrivateStorageResolver
{
    private string $appRoot;
    private ?string $documentRoot;

    public function __construct(?string $appRoot = null, ?string $documentRoot = null)
    {
        $resolved = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($resolved) || !is_dir($resolved) || is_link($appRoot ?? $resolved)) {
            throw new RuntimeException('Не удалось определить корень приложения для private storage');
        }

        $this->appRoot = self::stableNormalize($resolved);
        $this->documentRoot = $this->resolveDocumentRoot($documentRoot);
    }

    /**
     * Возвращает безопасный путь, не создавая каталог.
     * Подходит для read-only readiness и диагностики.
     */
    public function candidate(): string
    {
        $configured = trim((string) (getenv('PRIVATE_STORAGE_PATH') ?: ''));
        if ($configured !== '') {
            return $this->validateCandidate($configured);
        }

        $candidates = $this->automaticCandidates();

        foreach ($candidates as $candidate) {
            if (!is_dir($candidate) || is_link($candidate)) {
                continue;
            }

            try {
                return $this->validateCandidate($candidate);
            } catch (Throwable) {
                // Ищем следующий безопасный исторический путь.
            }
        }

        foreach ($candidates as $candidate) {
            try {
                return $this->validateCandidate($candidate);
            } catch (Throwable) {
                // Ищем следующий доступный родительский каталог.
            }
        }

        throw new RuntimeException(
            'Не удалось автоматически определить внешний private storage; '
            . 'рядом с приложением и в HOME нет доступного безопасного каталога'
        );
    }

    /**
     * Создаёт private storage при необходимости и возвращает realpath.
     */
    public function prepare(): string
    {
        $candidate = $this->candidate();

        if (!is_dir($candidate)) {
            $oldUmask = umask(0077);
            $created = @mkdir($candidate, 0700, true);
            umask($oldUmask);

            if (!$created && !is_dir($candidate)) {
                throw new RuntimeException('Не удалось создать private storage: ' . $candidate);
            }
        }

        @chmod($candidate, 0700);

        $resolved = realpath($candidate);
        if (!is_string($resolved) || !is_dir($resolved) || is_link($candidate)) {
            throw new RuntimeException('Private storage не удалось безопасно разрешить');
        }

        $resolved = $this->validateResolved($resolved);
        $probe = $resolved . DIRECTORY_SEPARATOR . '.private-storage-write-' . bin2hex(random_bytes(6));

        if (@file_put_contents($probe, 'ok', LOCK_EX) !== 2) {
            @unlink($probe);
            throw new RuntimeException('PHP не может записывать в private storage');
        }

        @chmod($probe, 0600);
        @unlink($probe);

        return $resolved;
    }

    /**
     * Подготавливает путь и публикует его для остальных компонентов текущего процесса.
     */
    public function prepareAndPublish(): string
    {
        $resolved = $this->prepare();

        if (!putenv('PRIVATE_STORAGE_PATH=' . $resolved)) {
            throw new RuntimeException('Не удалось опубликовать PRIVATE_STORAGE_PATH для текущего процесса');
        }

        $_ENV['PRIVATE_STORAGE_PATH'] = $resolved;
        $_SERVER['PRIVATE_STORAGE_PATH'] = $resolved;

        return $resolved;
    }

    /** @return list<string> */
    public function automaticCandidates(): array
    {
        $suffix = substr(hash('sha256', self::stableNormalize($this->appRoot)), 0, 10);
        $home = trim((string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')));

        $items = [
            dirname($this->appRoot) . DIRECTORY_SEPARATOR . '.workspace-organizer-private-' . $suffix,
        ];

        if ($home !== '') {
            $items[] = rtrim($home, '/\\')
                . DIRECTORY_SEPARATOR
                . '.workspace-organizer-private-'
                . $suffix;
        }

        $unique = [];
        foreach ($items as $item) {
            $normalized = self::stableNormalize($item);
            if ($normalized !== '') {
                $unique[$normalized] = true;
            }
        }

        return array_keys($unique);
    }

    private function validateCandidate(string $path): string
    {
        $path = self::stableNormalize($path);

        if ($path === '' || !UpdatePath::isAbsolute($path)) {
            throw new RuntimeException('Private storage должен иметь абсолютный путь');
        }

        if (preg_match('~(?:^|/)\.\.(?:/|$)~', $path) === 1 || is_link($path)) {
            throw new RuntimeException('Private storage содержит небезопасный путь');
        }

        $this->assertOutsideProtectedRoots($path);

        if (file_exists($path)) {
            if (!is_dir($path)) {
                throw new RuntimeException('Private storage указывает не на каталог');
            }

            $resolved = realpath($path);
            if (!is_string($resolved)) {
                throw new RuntimeException('Private storage не удалось разрешить');
            }

            return $this->validateResolved($resolved);
        }

        $parent = dirname($path);
        while ($parent !== '' && $parent !== dirname($parent) && !file_exists($parent)) {
            $parent = dirname($parent);
        }

        $resolvedParent = $parent !== '' ? realpath($parent) : false;
        if (!is_string($resolvedParent) || !is_dir($resolvedParent)) {
            throw new RuntimeException('Для private storage не найден существующий родительский каталог');
        }

        $this->assertOutsideProtectedRoots($resolvedParent);
        if (!is_writable($resolvedParent)) {
            throw new RuntimeException('Родительский каталог private storage недоступен на запись');
        }

        return $path;
    }

    private function validateResolved(string $path): string
    {
        $resolved = self::stableNormalize($path);
        $this->assertOutsideProtectedRoots($resolved);

        if (!is_writable($resolved)) {
            throw new RuntimeException('Private storage недоступен PHP на запись');
        }

        return $resolved;
    }

    private function assertOutsideProtectedRoots(string $path): void
    {
        if (UpdatePath::inside($path, $this->appRoot)) {
            throw new RuntimeException('Private storage должен находиться вне дерева приложения');
        }

        if ($this->documentRoot !== null && UpdatePath::inside($path, $this->documentRoot)) {
            throw new RuntimeException('Private storage должен находиться вне document root');
        }
    }

    private function resolveDocumentRoot(?string $documentRoot): ?string
    {
        if ($documentRoot === null) {
            $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        } else {
            $documentRoot = trim($documentRoot);
        }

        if ($documentRoot === '') {
            return null;
        }

        $resolved = realpath($documentRoot);
        if (is_string($resolved) && is_dir($resolved)) {
            return self::stableNormalize($resolved);
        }

        if (!UpdatePath::isAbsolute($documentRoot)) {
            return null;
        }

        return self::stableNormalize($documentRoot);
    }

    /**
     * Совпадает с исторической нормализацией install.php:
     * слеши унифицируются, но регистр drive letter не меняется,
     * чтобы сохранить прежний десятисимвольный suffix.
     */
    private static function stableNormalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }

        return rtrim(preg_replace('#/+#', '/', $path) ?? $path, '/');
    }
}
