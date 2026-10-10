<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Неблокирующая блокировка одной транзакции на всё применение или восстановление.
 * Она удерживается всей командой, а не только отдельными записями журнала.
 */
final class UpdateApplyOperationLock
{
    /** @var resource|null */
    private mixed $handle = null;
    private string $path;

    public function __construct(string $stateRoot, string $transactionId)
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', trim($transactionId)) !== 1) {
            throw new RuntimeException('Некорректный идентификатор транзакции для блокировки применения');
        }

        $root = realpath($stateRoot);
        if (!is_string($root) || !is_dir($root) || is_link($stateRoot)) {
            throw new RuntimeException('Не удалось безопасно определить каталог состояния обновлятора');
        }
        if (!is_writable($root)) {
            throw new RuntimeException('Каталог состояния обновлятора недоступен для записи');
        }

        $locksRoot = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'operation-locks';
        if (!file_exists($locksRoot)) {
            $oldUmask = umask(0077);
            $made = @mkdir($locksRoot, 0700, false);
            umask($oldUmask);
            if (!$made && !is_dir($locksRoot)) {
                throw new RuntimeException('Не удалось создать каталог блокировок применения');
            }
        }
        if (!is_dir($locksRoot) || is_link($locksRoot)) {
            throw new RuntimeException('Каталог блокировок применения небезопасен');
        }
        @chmod($locksRoot, 0700);

        $this->path = $locksRoot . DIRECTORY_SEPARATOR . trim($transactionId) . '.lock';
        if (is_link($this->path) || (file_exists($this->path) && !is_file($this->path))) {
            throw new RuntimeException('Путь блокировки применения небезопасен');
        }

        $oldUmask = umask(0077);
        $handle = @fopen($this->path, 'c');
        umask($oldUmask);
        if ($handle === false) {
            throw new RuntimeException('Не удалось открыть блокировку применения');
        }
        @chmod($this->path, 0600);

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new UpdateOperationBusyException(
                'Другая команда применения или восстановления уже выполняет эту транзакцию'
            );
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
