<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use RuntimeException;

/**
 * File-backed maintenance state for updater transactions.
 *
 * The marker intentionally lives outside the application tree and database so
 * maintenance remains enforceable while code/DB are being serviced. A corrupt
 * existing marker fails closed as active maintenance rather than silently
 * allowing writes.
 */
final class MaintenanceModeService
{
    public const STATE_FILENAME = 'workspace-maintenance.json';
    private const LOCK_FILENAME = '.workspace-maintenance.lock';
    private const SCHEMA = 1;
    private const MAX_STATE_BYTES = 16384;

    private string $appRoot;
    private ?string $explicitStateRoot;

    public function __construct(?string $stateRoot = null, ?string $appRoot = null)
    {
        $resolvedApp = realpath($appRoot ?? (defined('SITEPATH') ? SITEPATH : dirname(__DIR__, 2)));
        if (!is_string($resolvedApp) || !is_dir($resolvedApp)) {
            throw new RuntimeException('Application root cannot be resolved for maintenance mode');
        }
        $this->appRoot = $this->normalize($resolvedApp);
        $this->explicitStateRoot = $stateRoot !== null ? trim($stateRoot) : null;
    }

    /**
     * @return array{active:bool,valid:bool,transaction_id:?string,reason:string,started_at:?int,state_path:?string}
     */
    public function state(): array
    {
        $path = $this->statePath(false);
        if ($path === null || !file_exists($path)) {
            return $this->inactive($path);
        }
        if (!is_file($path) || is_link($path)) {
            return $this->invalidActive($path, 'Maintenance marker is not a regular file');
        }

        $size = filesize($path);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_STATE_BYTES) {
            return $this->invalidActive($path, 'Maintenance marker has an invalid size');
        }

        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            return $this->invalidActive($path, 'Maintenance marker cannot be read');
        }

        try {
            $state = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->invalidActive($path, 'Maintenance marker contains invalid JSON');
        }
        if (!is_array($state) || array_is_list($state)) {
            return $this->invalidActive($path, 'Maintenance marker must be an object');
        }

        $transactionId = (string) ($state['transaction_id'] ?? '');
        $reason = trim((string) ($state['reason'] ?? ''));
        $startedAt = $state['started_at'] ?? null;
        if (
            ($state['schema'] ?? null) !== self::SCHEMA
            || ($state['mode'] ?? null) !== 'update'
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1
            || !is_int($startedAt)
            || $startedAt <= 0
            || $reason === ''
            || mb_strlen($reason) > 240
        ) {
            return $this->invalidActive($path, 'Maintenance marker failed schema validation');
        }

        return [
            'active' => true,
            'valid' => true,
            'transaction_id' => $transactionId,
            'reason' => $reason,
            'started_at' => $startedAt,
            'state_path' => $path,
        ];
    }

    /**
     * @return array{active:bool,valid:bool,transaction_id:string,reason:string,started_at:int,state_path:string}
     */
    public function enter(string $transactionId, string $reason = 'Обновление приложения'): array
    {
        $transactionId = trim($transactionId);
        $reason = trim($reason);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1) {
            throw new RuntimeException('Invalid maintenance transaction id');
        }
        if ($reason === '' || mb_strlen($reason) > 240) {
            throw new RuntimeException('Maintenance reason must contain 1-240 characters');
        }

        $path = $this->statePath(true);
        if ($path === null) {
            throw new RuntimeException('Maintenance state root is not configured');
        }

        /** @var array{active:bool,valid:bool,transaction_id:string,reason:string,started_at:int,state_path:string} */
        return $this->withExclusiveLock($path, function () use ($path, $transactionId, $reason): array {
            $existing = $this->state();
            if ($existing['active']) {
                if ($existing['valid'] && hash_equals((string) $existing['transaction_id'], $transactionId)) {
                    /** @var array{active:bool,valid:bool,transaction_id:string,reason:string,started_at:int,state_path:string} $existing */
                    return $existing;
                }
                throw new RuntimeException('Maintenance mode is already owned by another or invalid transaction');
            }

            $payload = [
                'schema' => self::SCHEMA,
                'mode' => 'update',
                'transaction_id' => $transactionId,
                'reason' => $reason,
                'started_at' => time(),
            ];
            $bytes = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
            $tmp = dirname($path) . DIRECTORY_SEPARATOR . '.maintenance-' . bin2hex(random_bytes(8)) . '.tmp';

            $oldUmask = umask(0077);
            $handle = @fopen($tmp, 'xb');
            umask($oldUmask);
            if ($handle === false) {
                throw new RuntimeException('Cannot create temporary maintenance marker');
            }
            try {
                if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                    throw new RuntimeException('Cannot write complete maintenance marker');
                }
            } catch (\Throwable $e) {
                fclose($handle);
                @unlink($tmp);
                throw $e;
            }
            fclose($handle);
            @chmod($tmp, 0600);

            if (file_exists($path)) {
                @unlink($tmp);
                throw new RuntimeException('Maintenance marker appeared concurrently; refusing to replace it');
            }
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException('Cannot atomically enable maintenance mode');
            }

            $state = $this->state();
            if (!$state['active'] || !$state['valid'] || $state['transaction_id'] !== $transactionId) {
                throw new RuntimeException('Maintenance marker verification failed after activation');
            }

            return [
                'active' => true,
                'valid' => true,
                'transaction_id' => $transactionId,
                'reason' => $state['reason'],
                'started_at' => (int) $state['started_at'],
                'state_path' => (string) $state['state_path'],
            ];
        });
    }

    public function leave(string $transactionId, bool $force = false): void
    {
        $path = $this->statePath(false);
        if ($path === null || !file_exists($path)) {
            return;
        }

        $this->withExclusiveLock($path, function () use ($path, $transactionId, $force): void {
            if (!file_exists($path)) {
                return;
            }

            $state = $this->state();
            if (!$force) {
                if (!$state['valid']) {
                    throw new RuntimeException('Maintenance marker is invalid; explicit --force is required for recovery');
                }
                if (!hash_equals((string) $state['transaction_id'], trim($transactionId))) {
                    throw new RuntimeException('Maintenance mode belongs to another transaction');
                }
            }

            if (is_link($path)) {
                if ($force && @unlink($path)) {
                    return;
                }
                throw new RuntimeException('Maintenance marker is a symlink; explicit recovery failed');
            }
            if (!is_file($path) || !@unlink($path)) {
                throw new RuntimeException('Cannot remove maintenance marker');
            }
        });
    }

    public function configuredStateRoot(): ?string
    {
        $path = $this->statePath(false);
        return $path === null ? null : dirname($path);
    }

    /** @return mixed */
    private function withExclusiveLock(string $statePath, callable $callback): mixed
    {
        $lockPath = dirname($statePath) . DIRECTORY_SEPARATOR . self::LOCK_FILENAME;
        if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
            throw new RuntimeException('Maintenance lock path is unsafe');
        }

        $oldUmask = umask(0077);
        $lock = @fopen($lockPath, 'c');
        umask($oldUmask);
        if ($lock === false) {
            throw new RuntimeException('Cannot open maintenance transition lock');
        }
        @chmod($lockPath, 0600);

        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('Cannot acquire maintenance transition lock');
        }

        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function statePath(bool $createRoot): ?string
    {
        $root = $this->configuredRootValue();
        if ($root === null) {
            return null;
        }
        if (!$this->isAbsolute($root)) {
            throw new RuntimeException('Maintenance/update state root must be an absolute path');
        }
        if (is_link($root)) {
            throw new RuntimeException('Maintenance/update state root must not be a symlink');
        }

        if (!is_dir($root)) {
            if (!$createRoot) {
                return $this->normalize($root) . '/' . self::STATE_FILENAME;
            }
            $oldUmask = umask(0077);
            $made = @mkdir($root, 0700, true);
            umask($oldUmask);
            if (!$made && !is_dir($root)) {
                throw new RuntimeException('Cannot create maintenance/update state root');
            }
        }
        @chmod($root, 0700);

        $resolved = realpath($root);
        if (!is_string($resolved) || !is_dir($resolved)) {
            throw new RuntimeException('Maintenance/update state root cannot be resolved');
        }
        $resolved = $this->normalize($resolved);
        if ($this->pathInside($resolved, $this->appRoot)) {
            throw new RuntimeException('Maintenance/update state root must be outside the live application tree');
        }
        if ($createRoot && !is_writable($resolved)) {
            throw new RuntimeException('Maintenance/update state root is not writable');
        }

        return $resolved . '/' . self::STATE_FILENAME;
    }

    private function configuredRootValue(): ?string
    {
        if ($this->explicitStateRoot !== null && $this->explicitStateRoot !== '') {
            return rtrim($this->explicitStateRoot, '/\\');
        }
        $configured = getenv('UPDATE_STATE_PATH');
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/\\');
        }
        $private = getenv('PRIVATE_STORAGE_PATH');
        if (is_string($private) && trim($private) !== '') {
            return rtrim(trim($private), '/\\') . DIRECTORY_SEPARATOR . 'updates';
        }
        return null;
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

    /** @return array{active:bool,valid:bool,transaction_id:null,reason:string,started_at:null,state_path:?string} */
    private function inactive(?string $path): array
    {
        return [
            'active' => false,
            'valid' => true,
            'transaction_id' => null,
            'reason' => '',
            'started_at' => null,
            'state_path' => $path,
        ];
    }

    /** @return array{active:bool,valid:bool,transaction_id:null,reason:string,started_at:null,state_path:string} */
    private function invalidActive(string $path, string $reason): array
    {
        return [
            'active' => true,
            'valid' => false,
            'transaction_id' => null,
            'reason' => $reason,
            'started_at' => null,
            'state_path' => $path,
        ];
    }
}
