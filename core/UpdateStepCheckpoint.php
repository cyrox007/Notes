<?php

declare(strict_types=1);

namespace Core;

/** Долговечная контрольная точка; вызывающий код удерживает блокировку транзакции. */
final class UpdateStepCheckpoint
{
    public function __construct(private readonly string $path, private readonly string $identity)
    {
        if (is_link($path) || !is_dir(dirname($path)) || is_link(dirname($path))) {
            throw new \RuntimeException('Небезопасный путь контрольной точки обновления');
        }
    }

    /** @return array<string,mixed> */
    public function read(): array
    {
        if (!file_exists($this->path)) return [];
        if (is_link($this->path) || !is_file($this->path)) {
            throw new \RuntimeException('Контрольная точка обновления небезопасна');
        }
        $bytes = file_get_contents($this->path);
        $record = is_string($bytes) ? json_decode($bytes, true, 64, JSON_THROW_ON_ERROR) : null;
        if (!is_array($record) || ($record['schema'] ?? null) !== 1
            || !hash_equals($this->identity, (string) ($record['identity'] ?? ''))
            || !is_array($record['state'] ?? null)
            || !hash_equals((string) ($record['sha256'] ?? ''), hash('sha256', $this->encode($record['state'])))) {
            throw new \RuntimeException('Контрольная точка обновления повреждена или относится к другой операции');
        }
        return $record['state'];
    }

    /** @param array<string,mixed> $state */
    public function write(array $state): void
    {
        $bytes = $this->encode([
            'schema' => 1, 'identity' => $this->identity, 'state' => $state,
            'sha256' => hash('sha256', $this->encode($state)),
        ]);
        $temporary = $this->path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $old = umask(0077);
        $handle = @fopen($temporary, 'xb');
        umask($old);
        if ($handle === false) throw new \RuntimeException('Не удалось создать контрольную точку обновления');
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) {
                throw new \RuntimeException('Контрольная точка обновления записана не полностью');
            }
        } catch (\Throwable $error) {
            @unlink($temporary);
            throw $error;
        } finally {
            fclose($handle);
        }
        if (!@rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new \RuntimeException('Не удалось опубликовать контрольную точку обновления');
        }
        @chmod($this->path, 0600);
    }

    /** @param array<mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
