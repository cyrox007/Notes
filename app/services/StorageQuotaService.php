<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;

final class StorageQuotaService
{
    public const DEFAULT_QUOTA_BYTES = 1073741824;
    public const MIN_QUOTA_BYTES = 10485760;
    public const MAX_QUOTA_BYTES = 10995116277760;
    public const DEFAULT_SETTING_KEY = 'file_manager_default_quota_bytes';

    private DatabaseManager $db;
    private PermissionService $permissions;

    public function __construct(?DatabaseManager $db = null, ?PermissionService $permissions = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance();
        $this->permissions = $permissions ?? new PermissionService($this->db);
    }

    public function defaultQuotaBytes(): int
    {
        $value = $this->db->fetchValue(
            'SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1',
            [':setting_key' => self::DEFAULT_SETTING_KEY]
        );
        $quota = is_numeric($value) ? (int) $value : self::DEFAULT_QUOTA_BYTES;
        return $this->normalizeQuota($quota);
    }

    public function effectiveQuotaBytes(int $userId): int
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Некорректный пользователь');
        }
        $override = $this->db->fetchValue(
            'SELECT quota_bytes FROM user_storage_quotas WHERE user_id = :user_id LIMIT 1',
            [':user_id' => $userId]
        );
        return $override !== null ? $this->normalizeQuota((int) $override) : $this->defaultQuotaBytes();
    }

    public function usedBytes(int $userId): int
    {
        $value = $this->db->fetchValue(
            "SELECT COALESCE(SUM(size), 0) FROM user_files WHERE user_id = :user_id AND is_deleted = 0 AND type <> 'folder'",
            [':user_id' => $userId]
        );
        return max(0, (int) $value);
    }

    /** @return array{used_bytes:int,quota_bytes:int,remaining_bytes:int,percent:float} */
    public function usage(int $userId): array
    {
        $used = $this->usedBytes($userId);
        $quota = $this->effectiveQuotaBytes($userId);
        return [
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'remaining_bytes' => max(0, $quota - $used),
            'percent' => $quota > 0 ? round(min(100, ($used / $quota) * 100), 2) : 100.0,
        ];
    }

    public function acquireUploadLock(int $userId, int $timeoutSeconds = 5): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Некорректный пользователь');
        }
        $result = $this->db->fetchValue(
            'SELECT GET_LOCK(:lock_name, :timeout_seconds)',
            [':lock_name' => $this->lockName($userId), ':timeout_seconds' => max(1, min(15, $timeoutSeconds))]
        );
        if ((int) $result !== 1) {
            throw new DomainException('Хранилище занято другой загрузкой. Повторите попытку.', 503);
        }
    }

    public function releaseUploadLock(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $this->db->fetchValue('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $this->lockName($userId)]);
    }

    public function assertCanStore(int $userId, int $incomingBytes): void
    {
        if ($incomingBytes <= 0) {
            throw new InvalidArgumentException('Некорректный размер файла');
        }
        $usage = $this->usage($userId);
        if ($usage['used_bytes'] + $incomingBytes > $usage['quota_bytes']) {
            throw new DomainException('Недостаточно места в персональном хранилище', 413);
        }
    }

    /** @return list<array<string,mixed>> */
    public function adminUsage(int $actorId): array
    {
        $this->permissions->requirePermission($actorId, 'admin.settings.manage');
        $defaultQuota = $this->defaultQuotaBytes();
        $rows = $this->db->fetchAll(
            "SELECT u.id,u.username,u.email,u.firstname,u.lastname,u.is_active,u.account_status,
                    q.quota_bytes AS override_quota,
                    COALESCE(SUM(CASE WHEN f.is_deleted = 0 AND f.type <> 'folder' THEN f.size ELSE 0 END),0) AS used_bytes
             FROM users u
             LEFT JOIN user_storage_quotas q ON q.user_id = u.id
             LEFT JOIN user_files f ON f.user_id = u.id
             GROUP BY u.id,u.username,u.email,u.firstname,u.lastname,u.is_active,u.account_status,q.quota_bytes
             ORDER BY u.username ASC"
        );
        foreach ($rows as &$row) {
            $quota = $row['override_quota'] !== null ? (int) $row['override_quota'] : $defaultQuota;
            $used = (int) $row['used_bytes'];
            $row['effective_quota'] = $quota;
            $row['remaining_bytes'] = max(0, $quota - $used);
            $row['percent'] = $quota > 0 ? round(min(100, ($used / $quota) * 100), 2) : 100.0;
            $row['has_override'] = $row['override_quota'] !== null;
        }
        unset($row);
        return $rows;
    }

    public function setDefaultQuota(int $actorId, int $quotaBytes): void
    {
        $this->permissions->requirePermission($actorId, 'admin.settings.manage');
        $quotaBytes = $this->normalizeQuota($quotaBytes);
        $this->db->execute(
            "INSERT INTO system_settings (setting_key,setting_value,setting_type,category,description,is_editable)
             VALUES (:setting_key,:setting_value,'integer','file_manager','Default File Manager storage quota per user in bytes',1)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP",
            [':setting_key' => self::DEFAULT_SETTING_KEY, ':setting_value' => (string) $quotaBytes]
        );
    }

    public function setUserQuota(int $actorId, int $userId, ?int $quotaBytes): void
    {
        $this->permissions->requirePermission($actorId, 'admin.settings.manage');
        if ($userId <= 0 || !$this->db->fetchValue('SELECT id FROM users WHERE id = :id', [':id' => $userId])) {
            throw new InvalidArgumentException('Пользователь не найден');
        }

        $this->acquireUploadLock($userId);
        try {
            if ($quotaBytes === null) {
                $this->db->execute('DELETE FROM user_storage_quotas WHERE user_id = :user_id', [':user_id' => $userId]);
                return;
            }
            $quotaBytes = $this->normalizeQuota($quotaBytes);
            $this->db->execute(
                'INSERT INTO user_storage_quotas (user_id,quota_bytes) VALUES (:user_id,:quota_bytes) '
                . 'ON DUPLICATE KEY UPDATE quota_bytes = VALUES(quota_bytes), updated_at = CURRENT_TIMESTAMP',
                [':user_id' => $userId, ':quota_bytes' => $quotaBytes]
            );
        } finally {
            $this->releaseUploadLock($userId);
        }
    }

    private function normalizeQuota(int $quota): int
    {
        if ($quota < self::MIN_QUOTA_BYTES || $quota > self::MAX_QUOTA_BYTES) {
            throw new InvalidArgumentException('Квота должна быть от 10 МБ до 10 ТБ');
        }
        return $quota;
    }

    private function lockName(int $userId): string
    {
        return 'workspace-storage-quota-user-' . $userId;
    }
}
