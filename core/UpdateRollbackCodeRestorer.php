<?php

declare(strict_types=1);

namespace Core;

use JsonException;
use RuntimeException;
use Throwable;

/**
 * Restores release-owned live code from the verified pre-update snapshot.
 *
 * The release candidate is deliberately not consulted here. Once the live
 * mutation boundary has been crossed, the backup is the authoritative recovery
 * artifact. Target-only top-level entries are identified from the current live
 * tree and quarantined before the verified snapshot is restored.
 */
final class UpdateRollbackCodeRestorer
{
    /** @var list<string> */
    private const PRESERVED_ROOTS = [
        '.git',
        'vendor',
        'cache',
        'compile',
        'uploads',
        'notes-private-storage',
        '.logs',
    ];

    private string $appRoot;
    private string $parentRoot;

    public function __construct(string $appRoot)
    {
        $real = realpath($appRoot);
        if (!is_string($real) || !is_dir($real) || is_link($appRoot)) {
            throw new RuntimeException('Application root cannot be resolved safely for updater rollback');
        }
        $this->appRoot = $this->normalize($real);

        $parent = realpath(dirname($real));
        if (!is_string($parent) || !is_dir($parent) || !is_writable($parent)) {
            throw new RuntimeException('Application parent must be writable for updater rollback');
        }
        $this->parentRoot = $this->normalize($parent);
    }

    /** @return array<string,mixed> */
    public function restore(string $transactionId, string $backupDir): array
    {
        $this->validateTransactionId($transactionId);
        $backup = $this->loadVerifiedCodeBackup($backupDir, $transactionId);
        $backupTops = $this->topLevelsFromEntries($backup['entries']);
        $liveTops = $this->releaseOwnedLiveTopLevels();
        $entries = array_values(array_unique(array_merge($backupTops, $liveTops)));
        sort($entries, SORT_STRING);

        if ($entries === []) {
            throw new RuntimeException('Updater rollback found no release-owned entries to restore');
        }

        $scratch = $this->scratchPath($transactionId);
        if (is_dir($scratch) && !is_link($scratch)) {
            $this->removeTree($scratch);
        } elseif (file_exists($scratch) || is_link($scratch)) {
            throw new RuntimeException('Updater rollback scratch path is unsafe');
        }

        $oldUmask = umask(0077);
        $made = @mkdir($scratch, 0700, false);
        umask($oldUmask);
        if (!$made || !is_dir($scratch)) {
            throw new RuntimeException('Cannot create updater rollback scratch directory');
        }

        $restoreDir = $scratch . '/restore';
        $failedDir = $scratch . '/failed-release';
        if (!mkdir($restoreDir, 0700) || !mkdir($failedDir, 0700)) {
            $this->removeTree($scratch);
            throw new RuntimeException('Cannot initialize updater rollback scratch directories');
        }

        $backupCodeRoot = $backup['backup_dir'] . '/code';
        try {
            foreach ($backupTops as $name) {
                $source = $backupCodeRoot . '/' . $name;
                if (!file_exists($source) && !is_link($source)) {
                    throw new RuntimeException("Verified code backup is missing top-level entry: {$name}");
                }
                $this->copyEntry($source, $restoreDir . '/' . $name);
            }

            foreach ($entries as $entry) {
                if (!$this->safeTopLevelName($entry) || $this->isPreservedRoot($entry) || $this->isPreservedEnvName($entry)) {
                    throw new RuntimeException('Updater rollback contains unsafe top-level entry');
                }

                $live = $this->appRoot . '/' . $entry;
                $failed = $failedDir . '/' . $entry;
                $restore = $restoreDir . '/' . $entry;

                if (file_exists($live) || is_link($live)) {
                    if (!@rename($live, $failed)) {
                        throw new RuntimeException("Cannot quarantine failed release entry during rollback: {$entry}");
                    }
                }
                if ((file_exists($restore) || is_link($restore)) && !@rename($restore, $live)) {
                    throw new RuntimeException("Cannot restore rollback code entry: {$entry}");
                }
            }

            $this->verifyLiveAgainstBackup($backup['entries']);
        } catch (Throwable $e) {
            throw $e;
        }

        return [
            'scratch_dir' => $scratch,
            'entries' => $entries,
            'source' => 'verified_backup',
            'candidate_required' => false,
            'restored_at' => time(),
        ];
    }

    /** @return array{backup_dir:string,entries:list<array<string,mixed>>} */
    private function loadVerifiedCodeBackup(string $backupDir, string $transactionId): array
    {
        $input = $backupDir;
        $real = realpath($backupDir);
        if (!is_string($real) || !is_dir($real) || is_link($input)) {
            throw new RuntimeException('Updater rollback backup directory is missing or unsafe');
        }
        $real = $this->normalize($real);
        if ($this->inside($real, $this->appRoot)) {
            throw new RuntimeException('Updater rollback backup must remain outside the live application tree');
        }

        $backupJson = $real . '/backup.json';
        $bytes = is_file($backupJson) && !is_link($backupJson) ? file_get_contents($backupJson) : false;
        try {
            $backup = is_string($bytes) ? json_decode($bytes, true, 64, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $e) {
            throw new RuntimeException('Updater rollback backup manifest is invalid JSON', 0, $e);
        }

        if (
            !is_array($backup)
            || array_is_list($backup)
            || ($backup['schema'] ?? null) !== 1
            || !is_array($backup['code'] ?? null)
        ) {
            throw new RuntimeException('Updater rollback backup manifest is incomplete');
        }
        if (!hash_equals($transactionId, (string) ($backup['transaction_id'] ?? ''))) {
            throw new RuntimeException('Updater rollback backup belongs to another transaction');
        }
        if (!hash_equals($this->appRoot, $this->normalize((string) ($backup['application_root'] ?? '')))) {
            throw new RuntimeException('Updater rollback backup belongs to another application root');
        }

        $manifestName = (string) ($backup['code']['manifest'] ?? '');
        if (!$this->safeRelativePath($manifestName)) {
            throw new RuntimeException('Updater code backup manifest path is invalid');
        }
        $manifestPath = $real . '/' . $manifestName;
        $manifestBytes = is_file($manifestPath) && !is_link($manifestPath) ? file_get_contents($manifestPath) : false;
        $manifestHash = is_file($manifestPath) && !is_link($manifestPath) ? hash_file('sha256', $manifestPath) : false;
        try {
            $manifest = is_string($manifestBytes) ? json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $e) {
            throw new RuntimeException('Updater code backup manifest is invalid JSON', 0, $e);
        }

        if (
            !is_array($manifest)
            || array_is_list($manifest)
            || ($manifest['schema'] ?? null) !== 1
            || !is_array($manifest['entries'] ?? null)
            || !is_string($manifestHash)
            || !hash_equals((string) ($backup['code']['manifest_sha256'] ?? ''), $manifestHash)
        ) {
            throw new RuntimeException('Updater code backup manifest verification failed');
        }

        /** @var list<array<string,mixed>> $entries */
        $entries = array_values($manifest['entries']);
        if ($entries === []) {
            throw new RuntimeException('Updater code rollback snapshot is empty');
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('Updater code backup entry is malformed');
            }
            $relative = (string) ($entry['path'] ?? '');
            if (!$this->safeRelativePath($relative)) {
                throw new RuntimeException('Updater code backup contains unsafe relative path');
            }
            $path = $real . '/code/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $size = is_file($path) && !is_link($path) ? filesize($path) : false;
            $hash = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
            if (
                !is_int($size)
                || $size !== (int) ($entry['size'] ?? -1)
                || !is_string($hash)
                || !hash_equals((string) ($entry['sha256'] ?? ''), $hash)
            ) {
                throw new RuntimeException("Updater code rollback snapshot verification failed: {$relative}");
            }
        }

        return ['backup_dir' => $real, 'entries' => $entries];
    }

    /** @param list<array<string,mixed>> $entries @return list<string> */
    private function topLevelsFromEntries(array $entries): array
    {
        $tops = [];
        foreach ($entries as $entry) {
            $relative = (string) ($entry['path'] ?? '');
            if (!$this->safeRelativePath($relative)) {
                throw new RuntimeException('Unsafe code snapshot path');
            }
            $top = explode('/', $relative, 2)[0];
            if (!$this->isPreservedRoot($top) && !$this->isPreservedEnvName($top)) {
                $tops[$top] = true;
            }
        }
        $result = array_keys($tops);
        sort($result, SORT_STRING);
        return $result;
    }

    /** @return list<string> */
    private function releaseOwnedLiveTopLevels(): array
    {
        $items = scandir($this->appRoot);
        if (!is_array($items)) {
            throw new RuntimeException('Cannot enumerate live application root for rollback');
        }

        $tops = [];
        foreach ($items as $name) {
            if ($name === '.' || $name === '..' || $this->isPreservedRoot($name) || $this->isPreservedEnvName($name)) {
                continue;
            }
            if (!$this->safeTopLevelName($name)) {
                throw new RuntimeException('Live application contains unsafe top-level entry during rollback');
            }
            $tops[] = $name;
        }
        sort($tops, SORT_STRING);
        return $tops;
    }

    /** @param list<array<string,mixed>> $entries */
    private function verifyLiveAgainstBackup(array $entries): void
    {
        foreach ($entries as $entry) {
            $relative = (string) ($entry['path'] ?? '');
            $path = $this->appRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $size = is_file($path) && !is_link($path) ? filesize($path) : false;
            $hash = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
            if (
                !is_int($size)
                || $size !== (int) ($entry['size'] ?? -1)
                || !is_string($hash)
                || !hash_equals((string) ($entry['sha256'] ?? ''), $hash)
            ) {
                throw new RuntimeException("Restored live code failed backup verification: {$relative}");
            }
        }
    }

    private function copyEntry(string $source, string $destination): void
    {
        if (is_link($source)) {
            throw new RuntimeException('Updater rollback refuses symlink in code snapshot');
        }
        if (is_file($source)) {
            $this->ensureDirectory(dirname($destination));
            $input = @fopen($source, 'rb');
            $output = @fopen($destination, 'xb');
            if ($input === false || $output === false) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                throw new RuntimeException('Cannot copy updater rollback file into restore scratch');
            }
            try {
                $copied = stream_copy_to_stream($input, $output);
                if (!is_int($copied) || !fflush($output)) {
                    throw new RuntimeException('Updater rollback file copy did not complete');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            $mode = fileperms($source);
            @chmod($destination, is_int($mode) ? ($mode & 0777) : 0644);
            return;
        }
        if (!is_dir($source)) {
            throw new RuntimeException('Updater rollback snapshot contains unsupported filesystem entry');
        }

        $this->ensureDirectory($destination);
        $items = scandir($source);
        if (!is_array($items)) {
            throw new RuntimeException('Cannot read updater rollback snapshot directory');
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->copyEntry($source . '/' . $item, $destination . '/' . $item);
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            return;
        }
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Updater rollback scratch directory collides with existing entry');
        }
        $oldUmask = umask(0022);
        $ok = @mkdir($path, 0755, true);
        umask($oldUmask);
        if (!$ok && !is_dir($path)) {
            throw new RuntimeException('Cannot create updater rollback restore directory');
        }
        @chmod($path, 0755);
    }

    private function scratchPath(string $transactionId): string
    {
        return $this->parentRoot . '/.' . basename($this->appRoot) . '.update-rollback-' . $transactionId;
    }

    private function validateTransactionId(string $transactionId): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', trim($transactionId)) !== 1) {
            throw new RuntimeException('Invalid updater transaction id for rollback');
        }
    }

    private function safeRelativePath(string $path): bool
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

    private function safeTopLevelName(string $name): bool
    {
        return $name !== ''
            && $name !== '.'
            && $name !== '..'
            && !str_contains($name, '/')
            && !str_contains($name, '\\')
            && !str_contains($name, "\0");
    }

    private function isPreservedRoot(string $name): bool
    {
        return in_array($name, self::PRESERVED_ROOTS, true);
    }

    private function isPreservedEnvName(string $name): bool
    {
        return $name === '.env' || str_starts_with($name, '.env.');
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:/', $path) === 1) {
            $path = strtolower($path[0]) . substr($path, 1);
        }
        return $path;
    }

    private function inside(string $path, string $parent): bool
    {
        $path = $this->normalize($path);
        $parent = $this->normalize($parent);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }

    private function removeTree(string $dir): void
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
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
