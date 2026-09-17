<?php

declare(strict_types=1);

namespace Modules\Tasks;

use Core\DatabaseManager;
use Core\ProfileContentProvider;
use InvalidArgumentException;

final class TasksCapability implements ProfileContentProvider
{
    public function moduleId(): string
    {
        return 'tasks';
    }

    /** @return list<array<string,mixed>> */
    public function ownerProfileItems(int $userId, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db()->fetchAll(
            'SELECT uid, title, status, priority, due_date, is_profile_public, updated_at
             FROM tasks
             WHERE user_id = :user_id AND is_deleted = 0
             ORDER BY updated_at DESC
             LIMIT ' . $limit,
            [':user_id' => $userId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function publicProfileItems(int $userId, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db()->fetchAll(
            'SELECT uid, title, status, priority, due_date, updated_at
             FROM tasks
             WHERE user_id = :user_id AND is_deleted = 0 AND is_profile_public = 1
             ORDER BY updated_at DESC
             LIMIT ' . $limit,
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
            'SELECT id FROM tasks WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0 LIMIT 1',
            [':uid' => $uid, ':user_id' => $userId]
        );
        if ($id === null) {
            throw new InvalidArgumentException('Объект не найден или недоступен');
        }

        $this->db()->execute(
            'UPDATE tasks SET is_profile_public = :is_public WHERE id = :id AND user_id = :user_id AND is_deleted = 0',
            [':is_public' => $isPublic ? 1 : 0, ':id' => (int) $id, ':user_id' => $userId]
        );
    }

    /** @return array<string,mixed> */
    public function profileMetrics(int $userId): array
    {
        $row = $this->db()->fetchOne(
            "SELECT
                COUNT(*) AS tasks_count,
                SUM(CASE WHEN status IN ('pending','in_progress') THEN 1 ELSE 0 END) AS open_tasks_count
             FROM tasks
             WHERE user_id = :user_id AND is_deleted = 0",
            [':user_id' => $userId]
        ) ?: [];

        return [
            'tasks_count' => max(0, (int) ($row['tasks_count'] ?? 0)),
            'open_tasks_count' => max(0, (int) ($row['open_tasks_count'] ?? 0)),
        ];
    }

    private function db(): DatabaseManager
    {
        return DatabaseManager::getInstance();
    }
}
