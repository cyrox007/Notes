<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Неблокирующий lock всей пользовательской операции обновления.
 *
 * В отличие от apply-lock он охватывает также backup и candidate. Файловый
 * дескриптор освобождается операционной системой при гибели PHP-процесса, после
 * чего следующий HTTP-запрос может безопасно начать recovery.
 */
final class UpdateCoordinatorLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(string $stateRoot, string $transactionId)
    {
        $transactionId = trim($transactionId);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1) {
            throw new RuntimeException('Некорректный идентификатор транзакции для coordinator-lock');
        }

        $root = realpath($stateRoot);
        if (!is_string($root) || !is_dir($root) || is_link($stateRoot) || !is_writable($root)) {
            throw new RuntimeException('Каталог состояния updater недоступен для coordinator-lock');
        }

        $locksRoot = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'coordinator-locks';
        if (!file_exists($locksRoot)) {
            $oldUmask = umask(0077);
            $created = @mkdir($locksRoot, 0700, false);
            umask($oldUmask);
            if (!$created && !is_dir($locksRoot)) {
                throw new RuntimeException('Не удалось создать каталог coordinator-lock');
            }
        }
        if (!is_dir($locksRoot) || is_link($locksRoot)) {
            throw new RuntimeException('Каталог coordinator-lock небезопасен');
        }
        @chmod($locksRoot, 0700);

        $path = $locksRoot . DIRECTORY_SEPARATOR . $transactionId . '.lock';
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('Путь coordinator-lock небезопасен');
        }

        $oldUmask = umask(0077);
        $handle = @fopen($path, 'c');
        umask($oldUmask);
        if ($handle === false) {
            throw new RuntimeException('Не удалось открыть coordinator-lock');
        }
        @chmod($path, 0600);

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new UpdateCoordinatorBusyException(
                'Операция обновления с этой транзакцией уже выполняется'
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

final class UpdateCoordinatorBusyException extends RuntimeException
{
}
