<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once dirname(__DIR__, 3) . '/core/DatabaseManager.php';
require_once __DIR__ . '/NotificationEvent.php';

use Core\DatabaseManager;
use InvalidArgumentException;

final class DatabaseNotificationCenter
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const MAX_PAGE = 100000;

    private DatabaseManager $db;

    public function __construct(?DatabaseManager $db = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance();
    }

    /**
     * @param array{status?:mixed,category?:mixed,importance?:mixed,source?:mixed} $filters
     * @return array{items:list<array<string,mixed>>,pagination:array{page:int,limit:int,total:int,total_pages:int},unread_count:int}
     */
    public function listForUser(int $userId, array $filters = [], int $page = 1, int $limit = self::DEFAULT_LIMIT): array
    {
        $this->assertUserId($userId);
        [$page, $limit] = $this->normalizePagination($page, $limit);
        [$where, $params] = $this->buildFilters($userId, $filters);
        $whereSql = implode("\n               AND ", $where);

        $total = (int) ($this->db->fetchValue(
            'SELECT COUNT(*)
             FROM notifications n
             INNER JOIN notification_events e ON e.id = n.event_id
             WHERE ' . $whereSql,
            $params
        ) ?? 0);

        $offset = ($page - 1) * $limit;
        $rows = $this->db->fetchAll(
            'SELECT
                n.id,
                n.title,
                n.body,
                n.target_path,
                n.read_at,
                n.created_at,
                e.event_name,
                e.event_version,
                e.source_name,
                e.category,
                e.importance,
                e.occurred_at,
                (SELECT COUNT(*)
                 FROM notification_delivery_jobs dj_total
                 WHERE dj_total.notification_id = n.id) AS delivery_total,
                (SELECT COUNT(*)
                 FROM notification_delivery_jobs dj_sent
                 WHERE dj_sent.notification_id = n.id AND dj_sent.state = \'sent\') AS delivery_sent,
                (SELECT COUNT(*)
                 FROM notification_delivery_jobs dj_active
                 WHERE dj_active.notification_id = n.id
                   AND dj_active.state IN (\'pending\', \'processing\', \'retry\')) AS delivery_active,
                (SELECT COUNT(*)
                 FROM notification_delivery_jobs dj_failed
                 WHERE dj_failed.notification_id = n.id AND dj_failed.state = \'failed\') AS delivery_failed
             FROM notifications n
             INNER JOIN notification_events e ON e.id = n.event_id
             WHERE ' . $whereSql . '
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        $items = array_map($this->normalizeItem(...), $rows);
        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $limit),
            ],
            'unread_count' => $this->unreadCount($userId),
        ];
    }

    public function unreadCount(int $userId): int
    {
        $this->assertUserId($userId);
        return (int) ($this->db->fetchValue(
            'SELECT COUNT(*)
             FROM notifications
             WHERE recipient_user_id = :recipient_user_id
               AND read_at IS NULL',
            [':recipient_user_id' => $userId]
        ) ?? 0);
    }

    public function markRead(int $userId, int $notificationId): void
    {
        $this->setReadState($userId, $notificationId, true);
    }

    public function markUnread(int $userId, int $notificationId): void
    {
        $this->setReadState($userId, $notificationId, false);
    }

    public function markAllRead(int $userId): int
    {
        $this->assertUserId($userId);
        return (int) $this->db->execute(
            'UPDATE notifications
             SET read_at = :read_at
             WHERE recipient_user_id = :recipient_user_id
               AND read_at IS NULL',
            [
                ':read_at' => gmdate('Y-m-d H:i:s'),
                ':recipient_user_id' => $userId,
            ]
        );
    }

    private function setReadState(int $userId, int $notificationId, bool $read): void
    {
        $this->assertUserId($userId);
        if ($notificationId <= 0) {
            throw new InvalidArgumentException('Идентификатор уведомления должен быть положительным');
        }

        if ($read) {
            $this->db->execute(
                'UPDATE notifications
                 SET read_at = COALESCE(read_at, :read_at)
                 WHERE id = :id
                   AND recipient_user_id = :recipient_user_id',
                [
                    ':read_at' => gmdate('Y-m-d H:i:s'),
                    ':id' => $notificationId,
                    ':recipient_user_id' => $userId,
                ]
            );
            return;
        }

        $this->db->execute(
            'UPDATE notifications
             SET read_at = NULL
             WHERE id = :id
               AND recipient_user_id = :recipient_user_id',
            [
                ':id' => $notificationId,
                ':recipient_user_id' => $userId,
            ]
        );
    }

    /**
     * @param array{status?:mixed,category?:mixed,importance?:mixed,source?:mixed} $filters
     * @return array{0:list<string>,1:array<string,mixed>}
     */
    private function buildFilters(int $userId, array $filters): array
    {
        $where = ['n.recipient_user_id = :recipient_user_id'];
        $params = [':recipient_user_id' => $userId];

        $status = $this->filterString($filters['status'] ?? 'all', 'Состояние', 16);
        if (!in_array($status, ['all', 'unread', 'read'], true)) {
            throw new InvalidArgumentException('Состояние уведомлений должно быть all, unread или read');
        }
        if ($status === 'unread') {
            $where[] = 'n.read_at IS NULL';
        } elseif ($status === 'read') {
            $where[] = 'n.read_at IS NOT NULL';
        }

        $category = $this->optionalFilterString($filters['category'] ?? null, 'Категория', 80);
        if ($category !== null) {
            $where[] = 'e.category = :category';
            $params[':category'] = $category;
        }

        $importance = $this->optionalFilterString($filters['importance'] ?? null, 'Важность', 16);
        if ($importance !== null) {
            if (!in_array($importance, NotificationEvent::importanceLevels(), true)) {
                throw new InvalidArgumentException('Неизвестный уровень важности уведомления');
            }
            $where[] = 'e.importance = :importance';
            $params[':importance'] = $importance;
        }

        $source = $this->optionalFilterString($filters['source'] ?? null, 'Источник', 80);
        if ($source !== null) {
            $where[] = 'e.source_name = :source_name';
            $params[':source_name'] = $source;
        }

        return [$where, $params];
    }

    /** @return array{0:int,1:int} */
    private function normalizePagination(int $page, int $limit): array
    {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new InvalidArgumentException('Номер страницы должен быть в диапазоне 1..100000');
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('Размер страницы должен быть в диапазоне 1..100');
        }
        return [$page, $limit];
    }

    private function filterString(mixed $value, string $label, int $maxLength): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' должно быть строкой');
        }
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($value === '' || $length > $maxLength || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException($label . ' имеет недопустимое значение');
        }
        return $value;
    }

    private function optionalFilterString(mixed $value, string $label, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->filterString($value, $label, $maxLength);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeItem(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'target_path' => isset($row['target_path']) ? (string) $row['target_path'] : null,
            'read_at' => isset($row['read_at']) ? (string) $row['read_at'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'event_name' => (string) ($row['event_name'] ?? ''),
            'event_version' => (int) ($row['event_version'] ?? 1),
            'source' => (string) ($row['source_name'] ?? ''),
            'category' => (string) ($row['category'] ?? ''),
            'importance' => (string) ($row['importance'] ?? ''),
            'occurred_at' => (string) ($row['occurred_at'] ?? ''),
            'delivery' => $this->normalizeDelivery($row),
        ];
    }

    /** @param array<string,mixed> $row @return array{external:bool,state:string,sent:int,pending:int,failed:int} */
    private function normalizeDelivery(array $row): array
    {
        $total = max(0, (int) ($row['delivery_total'] ?? 0));
        $sent = max(0, (int) ($row['delivery_sent'] ?? 0));
        $pending = max(0, (int) ($row['delivery_active'] ?? 0));
        $failed = max(0, (int) ($row['delivery_failed'] ?? 0));

        if ($total === 0) {
            $state = 'none';
        } elseif ($failed > 0 && $sent > 0) {
            $state = 'partial';
        } elseif ($failed > 0 && $pending === 0) {
            $state = 'failed';
        } elseif ($pending > 0) {
            $state = 'pending';
        } elseif ($sent >= $total) {
            $state = 'sent';
        } else {
            $state = 'pending';
        }

        return [
            'external' => $total > 0,
            'state' => $state,
            'sent' => $sent,
            'pending' => $pending,
            'failed' => $failed,
        ];
    }

    private function assertUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Идентификатор пользователя должен быть положительным');
        }
    }
}
