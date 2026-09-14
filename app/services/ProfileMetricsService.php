<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use InvalidArgumentException;

final class ProfileMetricsService
{
    private DatabaseManager $db;
    private StorageQuotaService $storageQuota;

    public function __construct(?DatabaseManager $db = null, ?StorageQuotaService $storageQuota = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance();
        $this->storageQuota = $storageQuota ?? new StorageQuotaService($this->db);
    }

    /**
     * @return array{
     *   notes_count:int,
     *   tasks_count:int,
     *   open_tasks_count:int,
     *   files_count:int,
     *   storage:array{used_bytes:int,quota_bytes:int,remaining_bytes:int,percent:float}
     * }
     */
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

        return [
            'notes_count' => max(0, (int) ($row['notes_count'] ?? 0)),
            'tasks_count' => max(0, (int) ($row['tasks_count'] ?? 0)),
            'open_tasks_count' => max(0, (int) ($row['open_tasks_count'] ?? 0)),
            'files_count' => max(0, (int) ($row['files_count'] ?? 0)),
            'storage' => $this->storageQuota->usage($userId),
        ];
    }
}
