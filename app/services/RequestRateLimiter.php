<?php

declare(strict_types=1);

namespace App\Services;

use Core\RequestOrigin;
use RuntimeException;

final class RequestRateLimiter
{
    /** @return array{allowed:bool,retry_after:int,remaining:int} */
    public static function consume(string $bucket, string $subject, int $limit, int $windowSeconds): array
    {
        $limit = max(1, $limit);
        $windowSeconds = max(1, $windowSeconds);
        $root = self::storageRoot();
        $directory = $root . '/rate-limit';

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось подготовить хранилище rate limit');
        }
        @chmod($directory, 0700);

        $safeBucket = preg_replace('/[^a-z0-9_-]+/i', '-', $bucket) ?: 'request';
        $key = hash('sha256', $safeBucket . "\0" . $subject . "\0" . $windowSeconds);
        $path = $directory . '/' . $safeBucket . '-' . $key . '.json';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Не удалось открыть rate limit state');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Не удалось заблокировать rate limit state');
            }

            $now = time();
            rewind($handle);
            $raw = stream_get_contents($handle);
            $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $startedAt = is_array($state) ? (int) ($state['started_at'] ?? 0) : 0;
            $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;

            if ($startedAt <= 0 || ($now - $startedAt) >= $windowSeconds) {
                $startedAt = $now;
                $count = 0;
            }

            $count++;
            $retryAfter = max(1, $windowSeconds - ($now - $startedAt));
            $allowed = $count <= $limit;
            $remaining = max(0, $limit - $count);

            $payload = json_encode([
                'started_at' => $startedAt,
                'count' => $count,
            ], JSON_THROW_ON_ERROR);

            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $payload) === false) {
                throw new RuntimeException('Не удалось обновить rate limit state');
            }
            fflush($handle);
            @chmod($path, 0600);

            return [
                'allowed' => $allowed,
                'retry_after' => $retryAfter,
                'remaining' => $remaining,
            ];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function clientSubject(string $scope): string
    {
        $remoteAddress = RequestOrigin::clientIp($_SERVER);
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        return $scope . '|' . $remoteAddress . '|' . $path;
    }

    private static function storageRoot(): string
    {
        $configured = getenv('RATE_LIMIT_STORAGE_PATH');
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/\\');
        }

        $configured = getenv('PRIVATE_STORAGE_PATH');
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/\\');
        }

        return rtrim(sys_get_temp_dir(), '/\\') . '/workspace-private';
    }
}
