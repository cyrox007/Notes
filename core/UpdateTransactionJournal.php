<?php

declare(strict_types=1);

namespace Core;

use JsonException;
use RuntimeException;
use Throwable;

/**
 * External append-style state journal for updater transactions.
 *
 * The journal lives outside the live application tree and database so recovery
 * information remains available even if the new code or schema fails to boot.
 */
final class UpdateTransactionJournal
{
    private const SCHEMA = 1;
    private const MAX_BYTES = 262144;
    private const LOCK_FILENAME = '.update-transactions.lock';

    private string $root;
    private string $appRoot;

    public function __construct(string $stateRoot, ?string $appRoot = null)
    {
        $resolvedApp = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($resolvedApp) || !is_dir($resolvedApp)) {
            throw new RuntimeException('Application root cannot be resolved for update journal');
        }
        $this->appRoot = $this->normalize($resolvedApp);
        $this->root = $this->prepareExternalRoot($stateRoot) . DIRECTORY_SEPARATOR . 'transactions';
        if (!is_dir($this->root)) {
            $oldUmask = umask(0077);
            $made = @mkdir($this->root, 0700, true);
            umask($oldUmask);
            if (!$made && !is_dir($this->root)) {
                throw new RuntimeException('Cannot create updater transaction journal directory');
            }
        }
        if (is_link($this->root) || !is_dir($this->root) || !is_writable($this->root)) {
            throw new RuntimeException('Updater transaction journal directory is unsafe or not writable');
        }
        @chmod($this->root, 0700);
    }

    /**
     * @param array{transaction_id:string,installed_version:string,installed_version_code:int,target_version:string,target_version_code:int,package_sha256:string,stage_dir:string} $identity
     * @return array<string,mixed>
     */
    public function initialize(array $identity): array
    {
        $this->validateIdentity($identity);
        $transactionId = $identity['transaction_id'];

        return $this->withLock(function () use ($transactionId, $identity): array {
            $path = $this->journalPath($transactionId);
            if (file_exists($path)) {
                $existing = $this->readPath($path);
                $this->assertSameIdentity($existing, $identity);
                return $existing;
            }

            $now = time();
            $journal = [
                'schema' => self::SCHEMA,
                'transaction_id' => $transactionId,
                'state' => 'initialized',
                'installed_version' => $identity['installed_version'],
                'installed_version_code' => $identity['installed_version_code'],
                'target_version' => $identity['target_version'],
                'target_version_code' => $identity['target_version_code'],
                'package_sha256' => strtolower($identity['package_sha256']),
                'stage_dir' => $this->normalize($identity['stage_dir']),
                'live_mutation_started' => false,
                'backups' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'history' => [[
                    'at' => $now,
                    'state' => 'initialized',
                    'note' => 'Verified staged update attached to transaction',
                ]],
            ];
            $this->writeAtomic($path, $journal, false);
            return $this->readPath($path);
        });
    }

    /**
     * @param array<string,mixed> $backups
     * @return array<string,mixed>
     */
    public function recordBackups(string $transactionId, array $backups): array
    {
        $this->validateTransactionId($transactionId);
        $this->validateBackups($backups);

        return $this->withLock(function () use ($transactionId, $backups): array {
            $path = $this->journalPath($transactionId);
            if (!is_file($path) || is_link($path)) {
                throw new RuntimeException('Updater transaction journal does not exist');
            }
            $journal = $this->readPath($path);
            $state = (string) ($journal['state'] ?? '');

            if ($state === 'backup_verified') {
                $existing = $journal['backups'] ?? null;
                if (!is_array($existing) || hash('sha256', $this->canonicalJson($existing)) !== hash('sha256', $this->canonicalJson($backups))) {
                    throw new RuntimeException('Transaction already records a different backup set');
                }
                return $journal;
            }
            if ($state !== 'initialized') {
                throw new RuntimeException("Cannot record backups from updater transaction state {$state}");
            }
            if (($journal['live_mutation_started'] ?? true) !== false) {
                throw new RuntimeException('Cannot attach rollback backup after live mutation has started');
            }

            $now = time();
            $journal['backups'] = $backups;
            $journal['state'] = 'backup_verified';
            $journal['updated_at'] = $now;
            $history = is_array($journal['history'] ?? null) ? $journal['history'] : [];
            $history[] = [
                'at' => $now,
                'state' => 'backup_verified',
                'note' => 'Code snapshot and MySQL dump were created and hash-verified',
            ];
            $journal['history'] = $history;

            $this->writeAtomic($path, $journal, true);
            return $this->readPath($path);
        });
    }

    /** @return array<string,mixed> */
    public function load(string $transactionId): array
    {
        $this->validateTransactionId($transactionId);
        $path = $this->journalPath($transactionId);
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('Updater transaction journal does not exist');
        }
        return $this->readPath($path);
    }

    public function path(string $transactionId): string
    {
        $this->validateTransactionId($transactionId);
        return $this->journalPath($transactionId);
    }

    /** @param array<string,mixed> $identity */
    private function validateIdentity(array $identity): void
    {
        foreach (['transaction_id', 'installed_version', 'target_version', 'package_sha256', 'stage_dir'] as $key) {
            if (!isset($identity[$key]) || !is_string($identity[$key]) || trim($identity[$key]) === '') {
                throw new RuntimeException("Updater transaction identity is missing {$key}");
            }
        }
        foreach (['installed_version_code', 'target_version_code'] as $key) {
            if (!isset($identity[$key]) || !is_int($identity[$key]) || $identity[$key] <= 0) {
                throw new RuntimeException("Updater transaction identity has invalid {$key}");
            }
        }
        $this->validateTransactionId($identity['transaction_id']);
        if ($identity['target_version_code'] <= $identity['installed_version_code']) {
            throw new RuntimeException('Updater transaction target must be newer than installed version');
        }
        if (preg_match('/^[0-9a-f]{64}$/', strtolower($identity['package_sha256'])) !== 1) {
            throw new RuntimeException('Updater transaction package SHA-256 is invalid');
        }
        $stage = realpath($identity['stage_dir']);
        if (!is_string($stage) || !is_dir($stage) || is_link($identity['stage_dir'])) {
            throw new RuntimeException('Updater transaction stage directory cannot be resolved');
        }
        $stage = $this->normalize($stage);
        if ($this->pathInside($stage, $this->appRoot)) {
            throw new RuntimeException('Updater transaction stage must be outside the live application tree');
        }
    }

    /** @param array<string,mixed> $journal @param array<string,mixed> $identity */
    private function assertSameIdentity(array $journal, array $identity): void
    {
        $expected = [
            'transaction_id' => $identity['transaction_id'],
            'installed_version' => $identity['installed_version'],
            'installed_version_code' => $identity['installed_version_code'],
            'target_version' => $identity['target_version'],
            'target_version_code' => $identity['target_version_code'],
            'package_sha256' => strtolower($identity['package_sha256']),
            'stage_dir' => $this->normalize((string) realpath($identity['stage_dir'])),
        ];
        foreach ($expected as $key => $value) {
            if (($journal[$key] ?? null) !== $value) {
                throw new RuntimeException("Updater transaction id is already bound to different {$key}");
            }
        }
    }

    /** @param array<string,mixed> $backups */
    private function validateBackups(array $backups): void
    {
        foreach (['backup_dir', 'manifest_path', 'manifest_sha256', 'code', 'database'] as $key) {
            if (!array_key_exists($key, $backups)) {
                throw new RuntimeException("Updater backup metadata is missing {$key}");
            }
        }
        if (!is_string($backups['backup_dir']) || !is_dir($backups['backup_dir'])) {
            throw new RuntimeException('Updater backup directory is missing');
        }
        if (!is_string($backups['manifest_path']) || !is_file($backups['manifest_path'])) {
            throw new RuntimeException('Updater backup manifest is missing');
        }
        if (!is_string($backups['manifest_sha256']) || preg_match('/^[0-9a-f]{64}$/', $backups['manifest_sha256']) !== 1) {
            throw new RuntimeException('Updater backup manifest SHA-256 is invalid');
        }
        foreach (['code', 'database'] as $section) {
            if (!is_array($backups[$section])) {
                throw new RuntimeException("Updater backup {$section} metadata is invalid");
            }
        }
    }

    private function validateTransactionId(string $transactionId): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', trim($transactionId)) !== 1) {
            throw new RuntimeException('Invalid updater transaction id');
        }
    }

    /** @return array<string,mixed> */
    private function readPath(string $path): array
    {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Updater transaction journal path is unsafe');
        }
        $size = filesize($path);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('Updater transaction journal has an invalid size');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException('Cannot read updater transaction journal');
        }
        try {
            $journal = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Updater transaction journal is corrupt', 0, $e);
        }
        if (!is_array($journal) || array_is_list($journal) || ($journal['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('Updater transaction journal failed schema validation');
        }
        $id = (string) ($journal['transaction_id'] ?? '');
        $this->validateTransactionId($id);
        return $journal;
    }

    /** @param array<string,mixed> $journal */
    private function writeAtomic(string $path, array $journal, bool $replace): void
    {
        $bytes = json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('Updater transaction journal exceeds size limit');
        }
        $tmp = dirname($path) . DIRECTORY_SEPARATOR . '.journal-' . bin2hex(random_bytes(8)) . '.tmp';
        $oldUmask = umask(0077);
        $handle = @fopen($tmp, 'xb');
        umask($oldUmask);
        if ($handle === false) {
            throw new RuntimeException('Cannot create temporary updater transaction journal');
        }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new RuntimeException('Cannot write complete updater transaction journal');
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($tmp);
            throw $e;
        }
        fclose($handle);
        @chmod($tmp, 0600);

        if (!$replace && file_exists($path)) {
            @unlink($tmp);
            throw new RuntimeException('Updater transaction journal appeared concurrently');
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot atomically publish updater transaction journal');
        }
        @chmod($path, 0600);
    }

    /** @return mixed */
    private function withLock(callable $callback): mixed
    {
        $lockPath = $this->root . DIRECTORY_SEPARATOR . self::LOCK_FILENAME;
        if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
            throw new RuntimeException('Updater transaction lock path is unsafe');
        }
        $oldUmask = umask(0077);
        $lock = @fopen($lockPath, 'c');
        umask($oldUmask);
        if ($lock === false) {
            throw new RuntimeException('Cannot open updater transaction lock');
        }
        @chmod($lockPath, 0600);
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('Cannot acquire updater transaction lock');
        }
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function journalPath(string $transactionId): string
    {
        return $this->root . DIRECTORY_SEPARATOR . $transactionId . '.json';
    }

    private function prepareExternalRoot(string $path): string
    {
        $path = trim($path);
        if (!$this->isAbsolute($path) || is_link($path)) {
            throw new RuntimeException('Updater state root must be an absolute non-symlink path');
        }
        if (!is_dir($path)) {
            $oldUmask = umask(0077);
            $made = @mkdir($path, 0700, true);
            umask($oldUmask);
            if (!$made && !is_dir($path)) {
                throw new RuntimeException('Cannot create updater state root');
            }
        }
        $resolved = realpath($path);
        if (!is_string($resolved) || !is_dir($resolved) || !is_writable($resolved)) {
            throw new RuntimeException('Updater state root cannot be resolved or is not writable');
        }
        $resolved = $this->normalize($resolved);
        if ($this->pathInside($resolved, $this->appRoot)) {
            throw new RuntimeException('Updater state root must be outside the live application tree');
        }
        @chmod($resolved, 0700);
        return $resolved;
    }

    private function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $nested) {
                $item[$key] = $normalize($nested);
            }
            return $item;
        };
        return json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:/', $path) === 1) {
            $path = strtolower($path[0]) . substr($path, 1);
        }
        return $path;
    }

    private function pathInside(string $path, string $parent): bool
    {
        $path = $this->normalize($path);
        $parent = $this->normalize($parent);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }
}
