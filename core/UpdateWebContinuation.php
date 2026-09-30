<?php

declare(strict_types=1);

namespace Core;

use JsonException;
use RuntimeException;

/**
 * Короткоживущий токен продолжения web-обновления.
 *
 * Во внешнем state-root хранится только SHA-256 токена. Сам токен передаётся
 * браузеру один раз и используется исключительно для продолжения конкретной
 * транзакции через maintenance-барьер.
 */
final class UpdateWebContinuation
{
    private const SCHEMA = 1;
    private const LEASE_SECONDS = 180;
    private const MAX_BYTES = 4096;

    private string $root;

    public function __construct(string $stateRoot)
    {
        $resolved = realpath($stateRoot);
        if (!is_string($resolved) || !is_dir($resolved) || is_link($stateRoot) || !is_writable($resolved)) {
            throw new RuntimeException('Каталог состояния updater недоступен для web-продолжения');
        }

        $root = rtrim($resolved, '/\\') . DIRECTORY_SEPARATOR . 'web-continuations';
        if (!file_exists($root)) {
            $oldUmask = umask(0077);
            $created = @mkdir($root, 0700, false);
            umask($oldUmask);
            if (!$created && !is_dir($root)) {
                throw new RuntimeException('Не удалось создать каталог web-продолжения updater');
            }
        }
        if (!is_dir($root) || is_link($root) || !is_writable($root)) {
            throw new RuntimeException('Каталог web-продолжения updater небезопасен');
        }
        @chmod($root, 0700);
        $this->root = $root;
    }

    public function create(string $transactionId): string
    {
        $transactionId = $this->transactionId($transactionId);
        $token = $this->encode(random_bytes(32));
        $now = time();

        $this->write($transactionId, [
            'schema' => self::SCHEMA,
            'transaction_id' => $transactionId,
            'token_sha256' => hash('sha256', $token),
            'created_at' => $now,
            'expires_at' => $now + self::LEASE_SECONDS,
        ], false);

        return $token;
    }

    public function verify(string $transactionId, string $token): bool
    {
        $state = $this->read($this->transactionId($transactionId));
        if ($state === null || !$this->validState($state, $transactionId)) {
            return false;
        }

        if ((int) $state['expires_at'] < time()) {
            return false;
        }

        $token = trim($token);
        return $token !== ''
            && hash_equals((string) $state['token_sha256'], hash('sha256', $token));
    }

    public function verifyAndRenew(string $transactionId, string $token): bool
    {
        $transactionId = $this->transactionId($transactionId);
        if (!$this->verify($transactionId, $token)) {
            return false;
        }

        $state = $this->read($transactionId);
        if ($state === null || !$this->validState($state, $transactionId)) {
            return false;
        }
        $state['expires_at'] = time() + self::LEASE_SECONDS;
        $this->write($transactionId, $state, true);
        return true;
    }

    public function active(string $transactionId): bool
    {
        $transactionId = $this->transactionId($transactionId);
        $state = $this->read($transactionId);
        return $state !== null
            && $this->validState($state, $transactionId)
            && (int) $state['expires_at'] >= time();
    }

    public function revoke(string $transactionId): void
    {
        $path = $this->path($this->transactionId($transactionId));
        if (!file_exists($path)) {
            return;
        }
        if (is_link($path) || !is_file($path) || !@unlink($path)) {
            throw new RuntimeException('Не удалось удалить токен web-продолжения updater');
        }
    }

    private function transactionId(string $transactionId): string
    {
        $transactionId = trim($transactionId);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/D', $transactionId) !== 1) {
            throw new RuntimeException('Некорректный идентификатор web-транзакции updater');
        }
        return $transactionId;
    }

    /** @return array<string,mixed>|null */
    private function read(string $transactionId): ?array
    {
        $path = $this->path($transactionId);
        if (!file_exists($path)) {
            return null;
        }
        if (!is_file($path) || is_link($path)) {
            return null;
        }

        $size = filesize($path);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_BYTES) {
            return null;
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            return null;
        }

        try {
            $state = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        return is_array($state) && !array_is_list($state) ? $state : null;
    }

    /** @param array<string,mixed> $state */
    private function validState(array $state, string $transactionId): bool
    {
        return ($state['schema'] ?? null) === self::SCHEMA
            && is_string($state['transaction_id'] ?? null)
            && hash_equals($transactionId, (string) $state['transaction_id'])
            && is_string($state['token_sha256'] ?? null)
            && preg_match('/^[0-9a-f]{64}$/D', (string) $state['token_sha256']) === 1
            && is_int($state['created_at'] ?? null)
            && is_int($state['expires_at'] ?? null)
            && (int) $state['expires_at'] >= (int) $state['created_at'];
    }

    /** @param array<string,mixed> $state */
    private function write(string $transactionId, array $state, bool $replace): void
    {
        $path = $this->path($transactionId);
        if (!$replace && file_exists($path)) {
            throw new RuntimeException('Web-продолжение для этой транзакции уже существует');
        }

        $bytes = json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        $temp = $this->root . DIRECTORY_SEPARATOR
            . '.continuation-' . bin2hex(random_bytes(8)) . '.tmp';
        $oldUmask = umask(0077);
        $handle = @fopen($temp, 'xb');
        umask($oldUmask);
        if ($handle === false) {
            throw new RuntimeException('Не удалось создать временный токен web-продолжения');
        }

        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new RuntimeException('Не удалось записать токен web-продолжения полностью');
            }
        } finally {
            fclose($handle);
        }
        @chmod($temp, 0600);

        if ($replace && file_exists($path) && !@unlink($path)) {
            @unlink($temp);
            throw new RuntimeException('Не удалось обновить lease web-продолжения');
        }
        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('Не удалось атомарно сохранить web-продолжение');
        }
        @chmod($path, 0600);
    }

    private function path(string $transactionId): string
    {
        return $this->root . DIRECTORY_SEPARATOR . $transactionId . '.json';
    }

    private function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
