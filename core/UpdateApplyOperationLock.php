<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Holds a non-blocking transaction-scoped lock for the entire live apply/recover
 * command, not only for individual journal writes.
 */
final class UpdateApplyOperationLock
{
    /** @var resource|null */
    private $handle = null;
    private string $path;

    public function __construct(string $stateRoot, string $transactionId)
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', trim($transactionId)) !== 1) {
            throw new RuntimeException('Invalid updater transaction id for operation lock');
        }

        $root = realpath($stateRoot);
        if (!is_string($root) || !is_dir($root) || is_link($stateRoot)) {
            throw new RuntimeException('Updater state root cannot be resolved safely for operation lock');
        }
        if (!is_writable($root)) {
            throw new RuntimeException('Updater state root is not writable for operation lock');
        }

        $locksRoot = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'operation-locks';
        if (!file_exists($locksRoot)) {
            $oldUmask = umask(0077);
            $made = @mkdir($locksRoot, 0700, false);
            umask($oldUmask);
            if (!$made && !is_dir($locksRoot)) {
                throw new RuntimeException('Cannot create updater operation lock directory');
            }
        }
        if (!is_dir($locksRoot) || is_link($locksRoot)) {
            throw new RuntimeException('Updater operation lock directory is unsafe');
        }
        @chmod($locksRoot, 0700);

        $this->path = $locksRoot . DIRECTORY_SEPARATOR . trim($transactionId) . '.lock';
        if (is_link($this->path) || (file_exists($this->path) && !is_file($this->path))) {
            throw new RuntimeException('Updater operation lock path is unsafe');
        }

        $oldUmask = umask(0077);
        $handle = @fopen($this->path, 'c');
        umask($oldUmask);
        if ($handle === false) {
            throw new RuntimeException('Cannot open updater operation lock');
        }
        @chmod($this->path, 0600);

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new UpdateOperationBusyException('Another live apply/recovery process already owns this updater transaction');
        }

        $this->handle = $handle;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        @flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}

final class UpdateOperationBusyException extends RuntimeException
{
}
