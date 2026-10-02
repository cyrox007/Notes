<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use RuntimeException;

final class UserLifecycleService
{
    public const DEFAULT_RETENTION_DAYS = 30;

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    public function schedule(int $userId, int $retentionDays = self::DEFAULT_RETENTION_DAYS): string
    {
        if ($userId <= 0) {
            throw new RuntimeException('Некорректный пользователь');
        }

        $retentionDays = max(1, min(365, $retentionDays));
        $now = date('Y-m-d H:i:s');
        $purgeAfter = date('Y-m-d H:i:s', time() + ($retentionDays * 86400));

        $this->db->execute(
            "UPDATE users
             SET is_active = 0,
                 account_status = 'inactive',
                 deletion_requested_at = :requested_at,
                 purge_after = :purge_after,
                 updated_at = :updated_at
             WHERE id = :id
               AND anonymized_at IS NULL",
            [
                ':requested_at' => $now,
                ':purge_after' => $purgeAfter,
                ':updated_at' => $now,
                ':id' => $userId,
            ]
        );

        return $purgeAfter;
    }

    public function cancel(int $userId): void
    {
        if ($userId <= 0) {
            throw new RuntimeException('Некорректный пользователь');
        }

        $this->db->execute(
            'UPDATE users
             SET deletion_requested_at = NULL,
                 purge_after = NULL,
                 updated_at = :updated_at
             WHERE id = :id
               AND anonymized_at IS NULL',
            [':updated_at' => date('Y-m-d H:i:s'), ':id' => $userId]
        );
    }

    /** @return array{processed:int,blocked:int} */
    public function purgeDue(int $limit = 100): array
    {
        $result = (new RetentionService($this->db))->purgeScheduledAccounts($limit);
        return [
            'processed' => (int) ($result['accounts_purged'] ?? 0),
            'blocked' => (int) ($result['accounts_blocked'] ?? 0),
        ];
    }
}
