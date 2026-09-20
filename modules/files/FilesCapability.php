<?php

declare(strict_types=1);

namespace Modules\Files;

use App\Services\StorageQuotaService;
use Core\DatabaseManager;
use Core\ProfileContentProvider;
use InvalidArgumentException;
use Throwable;

final class FilesCapability implements ProfileContentProvider
{
    public function moduleId(): string
    {
        return 'files';
    }

    /** @return list<array<string,mixed>> */
    public function ownerProfileItems(int $userId, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db()->fetchAll(
            "SELECT uid, name, extension, type, size, is_profile_public, updated_at
             FROM user_files
             WHERE user_id = :user_id
               AND is_deleted = 0
               AND type <> 'folder'
               AND uid IS NOT NULL
             ORDER BY updated_at DESC
             LIMIT " . $limit,
            [':user_id' => $userId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function publicProfileItems(int $userId, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db()->fetchAll(
            "SELECT uid, name, extension, type, size, updated_at
             FROM user_files
             WHERE user_id = :user_id
               AND is_deleted = 0
               AND is_profile_public = 1
               AND type <> 'folder'
               AND uid IS NOT NULL
             ORDER BY updated_at DESC
             LIMIT " . $limit,
            [':user_id' => $userId]
        );
    }

    public function setProfileVisibility(int $userId, string $uid, bool $isPublic): void
    {
        $uid = trim($uid);
        if ($userId <= 0 || $uid === '' || strlen($uid) > 64) {
            throw new InvalidArgumentException('Некорректный объект публикации');
        }

        $id = $this->db()->fetchValue(
            "SELECT id FROM user_files
             WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0
               AND type <> 'folder' AND uid IS NOT NULL
             LIMIT 1",
            [':uid' => $uid, ':user_id' => $userId]
        );
        if ($id === null) {
            throw new InvalidArgumentException('Объект не найден или недоступен');
        }

        $this->db()->execute(
            'UPDATE user_files SET is_profile_public = :is_public WHERE id = :id AND user_id = :user_id AND is_deleted = 0',
            [':is_public' => $isPublic ? 1 : 0, ':id' => (int) $id, ':user_id' => $userId]
        );
    }

    /** @return array<string,mixed> */
    public function profileMetrics(int $userId): array
    {
        $db = $this->db();
        $filesCount = max(0, (int) $db->fetchValue(
            "SELECT COUNT(*) FROM user_files WHERE user_id = :user_id AND is_deleted = 0 AND type <> 'folder'",
            [':user_id' => $userId]
        ));

        $quota = new StorageQuotaService($db);
        try {
            $storage = $quota->usage($userId);
        } catch (Throwable $e) {
            error_log('Files capability storage metric fallback: ' . $e->getMessage());
            $used = $quota->usedBytes($userId);
            $limit = StorageQuotaService::DEFAULT_QUOTA_BYTES;
            $storage = [
                'used_bytes' => $used,
                'quota_bytes' => $limit,
                'remaining_bytes' => max(0, $limit - $used),
                'percent' => $limit > 0 ? round(min(100, ($used / $limit) * 100), 2) : 100.0,
            ];
        }

        return [
            'files_count' => $filesCount,
            'storage' => $storage,
        ];
    }

    private function db(): DatabaseManager
    {
        return DatabaseManager::getInstance();
    }
}
