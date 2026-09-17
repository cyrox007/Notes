<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use InvalidArgumentException;
use Throwable;

final class ProfileMetricsService
{
    private DatabaseManager $db;
    private StorageQuotaService $storageQuota;

    public function __construct(?DatabaseManager $db = null, ?StorageQuotaService $storageQuota = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance();
        $this->storageQuota = $storageQuota ?? new StorageQuotaService($this->db);
    }

    /** @return array<string,mixed> */
    public function summary(int $userId): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Некорректный пользователь');
        }

        $row = $this->db->fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM notes WHERE user_id = :notes_user_id AND is_deleted = 0) AS notes_count,
                (SELECT COUNT(*) FROM tasks WHERE user_id = :tasks_user_id AND is_deleted = 0) AS tasks_count,
                (SELECT COUNT(*) FROM tasks WHERE user_id = :open_tasks_user_id AND is_deleted = 0 AND status IN ('pending','in_progress')) AS open_tasks_count,
                (SELECT COUNT(*) FROM user_files WHERE user_id = :files_user_id AND is_deleted = 0 AND type <> 'folder') AS files_count",
            [
                ':notes_user_id' => $userId,
                ':tasks_user_id' => $userId,
                ':open_tasks_user_id' => $userId,
                ':files_user_id' => $userId,
            ]
        ) ?: [];

        $storage = $this->storageUsage($userId);

        return [
            'notes_count' => max(0, (int) ($row['notes_count'] ?? 0)),
            'tasks_count' => max(0, (int) ($row['tasks_count'] ?? 0)),
            'open_tasks_count' => max(0, (int) ($row['open_tasks_count'] ?? 0)),
            'files_count' => max(0, (int) ($row['files_count'] ?? 0)),
            'storage' => $storage + [
                'used_label' => $this->formatBytes((int) $storage['used_bytes']),
                'quota_label' => $this->formatBytes((int) $storage['quota_bytes']),
            ],
        ];
    }

    /** @return array{used_bytes:int,quota_bytes:int,remaining_bytes:int,percent:float} */
    private function storageUsage(int $userId): array
    {
        try {
            return $this->storageQuota->usage($userId);
        } catch (Throwable $e) {
            // Profile remains usable during partial/legacy schema recovery. The
            // installer/migration gates still require quota tables in production.
            error_log('Profile storage metric fallback: ' . $e->getMessage());
            $used = $this->storageQuota->usedBytes($userId);
            $quota = StorageQuotaService::DEFAULT_QUOTA_BYTES;
            return [
                'used_bytes' => $used,
                'quota_bytes' => $quota,
                'remaining_bytes' => max(0, $quota - $used),
                'percent' => $quota > 0 ? round(min(100, ($used / $quota) * 100), 2) : 100.0,
            ];
        }
    }

    private function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        $precision = $unit === 0 ? 0 : ($value >= 10 ? 1 : 2);
        return number_format($value, $precision, ',', ' ') . ' ' . $units[$unit];
    }
}
