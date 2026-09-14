<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class FileLifecycleService
{
    private DatabaseManager $db;

    public function __construct(?DatabaseManager $db = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance();
    }

    /**
     * Soft-delete a file or folder subtree first, then best-effort remove physical files.
     *
     * @return array{deleted_records:int,deleted_files:int,cleanup_failures:int}
     */
    public function softDeleteTree(int $userId, int $rootId): array
    {
        if ($userId <= 0 || $rootId <= 0) {
            throw new InvalidArgumentException('Некорректный идентификатор');
        }

        $root = $this->db->fetchOne(
            'SELECT id,parent_id,type,path FROM user_files '
            . 'WHERE id = :id AND user_id = :user_id AND is_deleted = 0 LIMIT 1',
            [':id' => $rootId, ':user_id' => $userId]
        );
        if (!$root) {
            throw new DomainException('Файл не найден', 404);
        }

        $rows = $this->collectSubtree($userId, $root);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        $this->db->beginTransaction();
        try {
            foreach (array_chunk($ids, 200) as $chunk) {
                $params = [':user_id' => $userId];
                $placeholders = [];
                foreach ($chunk as $index => $id) {
                    $name = ':id_' . $index;
                    $placeholders[] = $name;
                    $params[$name] = $id;
                }

                $this->db->execute(
                    'UPDATE user_files SET is_deleted = 1, updated_at = CURRENT_TIMESTAMP '
                    . 'WHERE user_id = :user_id AND is_deleted = 0 AND id IN (' . implode(',', $placeholders) . ')',
                    $params
                );
            }
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }

        $deletedFiles = 0;
        $cleanupFailures = 0;
        foreach ($rows as $row) {
            if ((string) ($row['type'] ?? '') === 'folder' || empty($row['path'])) {
                continue;
            }

            $path = $this->resolveStoredPath((string) $row['path']);
            if ($path === null || !is_file($path)) {
                continue;
            }

            if (@unlink($path)) {
                $deletedFiles++;
                continue;
            }

            $cleanupFailures++;
            error_log('File Manager cleanup pending for path: ' . $path);
        }

        return [
            'deleted_records' => count($ids),
            'deleted_files' => $deletedFiles,
            'cleanup_failures' => $cleanupFailures,
        ];
    }

    /**
     * Compare DB metadata with File Manager physical storage.
     * Deleted leftovers can optionally be removed; active missing files are report-only.
     *
     * @return array{active_missing:list<int>,deleted_leftovers:list<int>,deleted_cleaned:int,blocked_paths:list<int>}
     */
    public function reconcile(bool $cleanupDeleted = false): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id,path,is_deleted FROM user_files WHERE type <> 'folder' AND path IS NOT NULL AND path <> ''"
        );

        $activeMissing = [];
        $deletedLeftovers = [];
        $blockedPaths = [];
        $deletedCleaned = 0;

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $path = $this->resolveStoredPath((string) $row['path']);
            if ($path === null) {
                $blockedPaths[] = $id;
                continue;
            }

            $exists = is_file($path);
            if ((int) $row['is_deleted'] === 0) {
                if (!$exists) {
                    $activeMissing[] = $id;
                }
                continue;
            }

            if (!$exists) {
                continue;
            }

            $deletedLeftovers[] = $id;
            if ($cleanupDeleted && @unlink($path)) {
                $deletedCleaned++;
            }
        }

        return [
            'active_missing' => $activeMissing,
            'deleted_leftovers' => $deletedLeftovers,
            'deleted_cleaned' => $deletedCleaned,
            'blocked_paths' => $blockedPaths,
        ];
    }

    /** @param array<string,mixed> $root @return list<array<string,mixed>> */
    private function collectSubtree(int $userId, array $root): array
    {
        $rows = [$root];
        $frontier = [(int) $root['id']];
        $seen = [(int) $root['id'] => true];

        while ($frontier !== []) {
            $params = [':user_id' => $userId];
            $placeholders = [];
            foreach ($frontier as $index => $parentId) {
                $name = ':parent_' . $index;
                $placeholders[] = $name;
                $params[$name] = $parentId;
            }

            $children = $this->db->fetchAll(
                'SELECT id,parent_id,type,path FROM user_files '
                . 'WHERE user_id = :user_id AND is_deleted = 0 AND parent_id IN (' . implode(',', $placeholders) . ')',
                $params
            );

            $frontier = [];
            foreach ($children as $child) {
                $id = (int) $child['id'];
                if ($id <= 0 || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $rows[] = $child;
                // The schema does not constrain parent_id to folders. Traverse every
                // child id so legacy or malformed descendants cannot stay active.
                $frontier[] = $id;
            }
        }

        return $rows;
    }

    private function resolveStoredPath(string $storedPath): ?string
    {
        $candidate = $this->isAbsolutePath($storedPath)
            ? $storedPath
            : rtrim(SITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($storedPath, '/\\');

        $roots = [
            $this->privateStorageRoot(),
            rtrim(SITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'file_manager',
        ];

        $managedRoot = null;
        foreach ($roots as $root) {
            if ($this->isPathWithin($candidate, $root)) {
                $managedRoot = $root;
                break;
            }
        }
        if ($managedRoot === null) {
            error_log('Blocked File Manager path outside storage roots: ' . $storedPath);
            return null;
        }

        $realPath = realpath($candidate);
        if ($realPath === false) {
            // The path may legitimately be missing during reconciliation. Lexical root
            // validation above is sufficient because no filesystem action will occur.
            return $candidate;
        }

        $realRoot = realpath($managedRoot);
        if ($realRoot === false || !$this->isPathWithin($realPath, $realRoot)) {
            error_log('Blocked File Manager symlink/path escape: ' . $storedPath);
            return null;
        }

        return $realPath;
    }

    private function privateStorageRoot(): string
    {
        $configured = getenv('PRIVATE_STORAGE_PATH');
        $root = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : dirname(SITEPATH) . DIRECTORY_SEPARATOR . 'notes-private-storage';
        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private function isPathWithin(string $candidate, string $root): bool
    {
        $candidate = $this->normalizePath($candidate);
        $root = rtrim($this->normalizePath($root), '/');
        return $candidate === $root || str_starts_with($candidate, $root . '/');
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
        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1 && $parts !== []) {
            $prefix = '';
        }
        return $prefix . implode('/', $parts);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
