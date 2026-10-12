<?php

declare(strict_types=1);

namespace Modules\Tasks;

use App\Services\PermissionService;
use Core\DatabaseManager;
use Core\WorkspaceCalendarLinkTargetProvider;
use DomainException;
use InvalidArgumentException;

final class TasksCalendarLinkProvider implements WorkspaceCalendarLinkTargetProvider
{
    public function __construct(private ?DatabaseManager $db = null)
    {
    }

    private function database(): DatabaseManager
    {
        return $this->db ??= DatabaseManager::getInstance();
    }

    public function listCalendarLinkTargets(int $userId, string $query = '', int $limit = 20): array
    {
        $this->requireAccess($userId);
        $query = $this->normalizeQuery($query);
        $limit = max(1, min(50, $limit));
        $params = [':user_id' => $userId];
        $where = 'user_id = :user_id AND is_deleted = 0';
        if ($query !== '') {
            $where .= ' AND title LIKE :query';
            $params[':query'] = '%' . $query . '%';
        }

        $rows = $this->database()->fetchAll(
            'SELECT uid,title,status,priority,due_date FROM tasks WHERE ' . $where
            . ' ORDER BY updated_at DESC, id DESC LIMIT ' . $limit,
            $params
        );
        return array_values(array_map(fn (array $row): array => $this->target($row), $rows));
    }

    public function resolveCalendarLinkTarget(int $userId, string $uid): array
    {
        $this->requireAccess($userId);
        $row = $this->database()->fetchOne(
            'SELECT uid,title,status,priority,due_date FROM tasks '
            . 'WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0 LIMIT 1',
            [':uid' => $this->normalizeUid($uid), ':user_id' => $userId]
        );
        if (!is_array($row)) {
            throw new DomainException('Задача не найдена или недоступна', 404);
        }
        return $this->target($row);
    }

    private function requireAccess(int $userId): void
    {
        if ($userId <= 0) {
            throw new DomainException('Требуется авторизация', 401);
        }
        (new PermissionService($this->database()))->requirePermission($userId, 'tasks.use');
    }

    private function normalizeQuery(string $query): string
    {
        $query = trim($query);
        if (mb_strlen($query) > 190) {
            throw new InvalidArgumentException('Слишком длинный поисковый запрос');
        }
        return $query;
    }

    private function normalizeUid(string $uid): string
    {
        $uid = trim($uid);
        if ($uid === '' || strlen($uid) > 64) {
            throw new InvalidArgumentException('Некорректный идентификатор задачи');
        }
        return $uid;
    }

    /** @param array<string,mixed> $row @return array{uid:string,title:string,subtitle:string,href:string} */
    private function target(array $row): array
    {
        $status = match ((string) ($row['status'] ?? '')) {
            'completed' => 'Выполнена',
            'in_progress' => 'В работе',
            'review' => 'На проверке',
            'studying' => 'Изучение',
            default => 'Ожидает',
        };
        $due = trim((string) ($row['due_date'] ?? ''));
        $subtitle = $due !== '' ? $status . ' · срок ' . $due : $status;

        return [
            'uid' => (string) $row['uid'],
            'title' => (string) $row['title'],
            'subtitle' => $subtitle,
            'href' => '/tasks/',
        ];
    }
}
