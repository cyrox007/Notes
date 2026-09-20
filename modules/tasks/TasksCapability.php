<?php

declare(strict_types=1);

namespace Modules\Tasks;

use App\Services\PermissionService;
use App\Services\RolePolicyService;
use Core\DatabaseManager;
use Core\ProfileContentProvider;
use Core\WorkspaceTaskCreator;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

final class TasksCapability implements ProfileContentProvider, WorkspaceTaskCreator
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

    /** @return array{uid:string,title:string,priority:string,due_date:?string} */
    public function createWorkspaceTask(
        int $userId,
        string $title,
        string $description,
        string $priority = 'medium',
        ?string $dueDate = null
    ): array {
        if ($userId <= 0) {
            throw new DomainException('Требуется авторизация', 401);
        }

        $db = $this->db();
        (new PermissionService($db))->requirePermission($userId, 'tasks.use');

        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Название задачи должно содержать от 1 до 255 символов');
        }

        $description = trim($description);
        if (mb_strlen($description) > 10000) {
            throw new InvalidArgumentException('Описание задачи слишком длинное');
        }

        $priority = strtolower(trim($priority));
        if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
            throw new InvalidArgumentException('Некорректный приоритет задачи');
        }

        $normalizedDueDate = null;
        $dueDate = trim((string) $dueDate);
        if ($dueDate !== '') {
            foreach (['Y-m-d\\TH:i', 'Y-m-d\\TH:i:s', 'Y-m-d H:i:s'] as $format) {
                $date = DateTimeImmutable::createFromFormat($format, $dueDate);
                if ($date instanceof DateTimeImmutable && $date->format($format) === $dueDate) {
                    $normalizedDueDate = $date->format('Y-m-d H:i:s');
                    break;
                }
            }
            if ($normalizedDueDate === null) {
                throw new InvalidArgumentException('Некорректная дата выполнения');
            }
        }

        $limit = (int) (new RolePolicyService($db))->effectiveValue($userId, 'tasks', 'max_personal_tasks');
        if ($limit > 0) {
            $count = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM tasks WHERE user_id = :user_id AND is_deleted = 0',
                [':user_id' => $userId]
            );
            if ($count >= $limit) {
                throw new DomainException('Достигнут лимит личных задач для вашей роли', 403);
            }
        }

        $uid = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        $db->execute(
            'INSERT INTO tasks (
                uid,user_id,title,description,status,priority,due_date,completed_at,
                is_deleted,deleted_at,created_at,updated_at
             ) VALUES (
                :uid,:user_id,:title,:description,"pending",:priority,:due_date,NULL,
                0,NULL,:created_at,:updated_at
             )',
            [
                ':uid' => $uid,
                ':user_id' => $userId,
                ':title' => $title,
                ':description' => $description === '' ? null : $description,
                ':priority' => $priority,
                ':due_date' => $normalizedDueDate,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );

        return [
            'uid' => $uid,
            'title' => $title,
            'priority' => $priority,
            'due_date' => $normalizedDueDate,
        ];
    }

    private function db(): DatabaseManager
    {
        return DatabaseManager::getInstance();
    }
}
