<?php

declare(strict_types=1);

namespace Modules\Tasks;

use Core\DatabaseManager;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Единая очередь личных и назначенных командных задач.
 * Права командных задач проверяются при чтении, даже если назначение сохранилось.
 */
final class TaskAssigneeQueueService
{
    private const MAX_PER_SOURCE = 300;
    private const PAGE_LIMIT = 30;

    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /** @return array<string,mixed> */
    public function forUser(int $userId, DateTimeImmutable $now): array
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Требуется авторизованный пользователь');
        }
        $bounds = TaskPersonalQueue::boundaries($now);
        $params = [
            ':user' => $userId,
            ':day_end' => $bounds['after_tomorrow'],
        ];
        $personal = $this->db->fetchAll(
            "SELECT t.uid,t.title,t.status,t.priority,t.due_date,t.version,
                    'personal' AS source,NULL AS board_uid,NULL AS board_name
             FROM tasks t
             WHERE t.user_id=:user AND t.is_deleted=0 AND t.archived_at IS NULL
               AND t.due_date IS NOT NULL AND t.due_date < :day_end
               AND t.status NOT IN ('completed','cancelled')
             ORDER BY t.due_date ASC,t.id ASC
             LIMIT " . self::MAX_PER_SOURCE,
            $params
        );
        $assigned = $this->db->fetchAll(
            "SELECT i.uid,i.title,i.status,i.priority,i.due_date,i.version,
                    'board' AS source,b.uid AS board_uid,b.name AS board_name
             FROM task_board_assignees a
             INNER JOIN task_board_items i ON i.id=a.task_id
             INNER JOIN task_boards b ON b.id=i.board_id
             INNER JOIN users u ON u.id=:user AND u.is_active=1 AND u.account_status='active'
             LEFT JOIN task_board_members bm ON bm.board_id=b.id AND bm.user_id=u.id
             WHERE a.user_id=:assigned_user AND i.is_deleted=0 AND i.archived_at IS NULL
               AND b.is_archived=0 AND i.due_date IS NOT NULL AND i.due_date < :day_end_board
               AND i.status NOT IN ('completed','cancelled')
               AND (b.owner_user_id=u.id OR bm.user_id IS NOT NULL OR b.audience='all_active')
             ORDER BY i.due_date ASC,i.id ASC
             LIMIT " . self::MAX_PER_SOURCE,
            [
                ':user' => $userId, ':assigned_user' => $userId,
                ':day_end_board' => $bounds['after_tomorrow'],
            ]
        );

        $groups = ['overdue' => [], 'today' => [], 'tomorrow' => []];
        foreach (array_merge($personal, $assigned) as $row) {
            $due = (string) $row['due_date'];
            $group = match (true) {
                $due < $bounds['today'] => 'overdue',
                $due < $bounds['tomorrow'] => 'today',
                default => 'tomorrow',
            };
            $groups[$group][] = [
                'uid' => (string) $row['uid'],
                'source' => (string) $row['source'],
                'board_uid' => $row['board_uid'],
                'board_name' => $row['board_name'],
                'title' => (string) $row['title'],
                'status' => (string) $row['status'],
                'priority' => (string) $row['priority'],
                'due_date' => $due,
                'version' => (int) $row['version'],
            ];
        }

        // Точные итоги считаются в БД, а не по ограниченному предпросмотру.
        $summary = $this->summary($userId, $bounds);
        $hasMore = [];
        foreach ($groups as $key => &$items) {
            usort($items, static fn(array $a, array $b): int =>
                [$a['due_date'], $a['source'], $a['uid']]
                <=> [$b['due_date'], $b['source'], $b['uid']]
            );
            $hasMore[$key] = $summary[$key] > self::PAGE_LIMIT;
            $items = array_slice($items, 0, self::PAGE_LIMIT);
        }
        unset($items);

        return [
            'date' => substr($bounds['today'], 0, 10),
            'timezone' => $now->getTimezone()->getName(),
            'groups' => $groups,
            'summary' => $summary,
            'summary_generated_at' => $now->format('Y-m-d H:i:sP'),
            'has_more' => $hasMore,
            'limit_per_group' => self::PAGE_LIMIT,
        ];
    }

    /**
     * Точные количества по всем подходящим задачам, независимо от лимита карточек.
     * @param array{today:string,tomorrow:string,after_tomorrow:string,now:string} $bounds
     * @return array{overdue:int,today:int,tomorrow:int,total:int}
     */
    private function summary(int $userId, array $bounds): array
    {
        $result = [];
        foreach (['overdue', 'today', 'tomorrow'] as $bucket) {
            $start = match ($bucket) {
                'today' => $bounds['today'],
                'tomorrow' => $bounds['tomorrow'],
                default => null,
            };
            $end = match ($bucket) {
                'overdue' => $bounds['today'],
                'today' => $bounds['tomorrow'],
                default => $bounds['after_tomorrow'],
            };
            $range = 'due_date < :end';
            $params = [':end' => $end];
            if ($start !== null) {
                $range .= ' AND due_date >= :start';
                $params[':start'] = $start;
            }
            $personal = (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM tasks
                 WHERE user_id=:user AND is_deleted=0 AND archived_at IS NULL
                   AND due_date IS NOT NULL AND status NOT IN ('completed','cancelled')
                   AND " . $range,
                [':user' => $userId, ...$params]
            );
            $board = (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM task_board_assignees a
                 INNER JOIN task_board_items i ON i.id=a.task_id
                 INNER JOIN task_boards b ON b.id=i.board_id
                 INNER JOIN users u ON u.id=:user AND u.is_active=1 AND u.account_status='active'
                 LEFT JOIN task_board_members bm ON bm.board_id=b.id AND bm.user_id=u.id
                 WHERE a.user_id=:assigned AND i.is_deleted=0 AND i.archived_at IS NULL
                   AND b.is_archived=0 AND i.due_date IS NOT NULL
                   AND i.status NOT IN ('completed','cancelled')
                   AND (b.owner_user_id=u.id OR bm.user_id IS NOT NULL OR b.audience='all_active')
                   AND " . str_replace('due_date', 'i.due_date', $range),
                [':user' => $userId, ':assigned' => $userId, ...$params]
            );
            $result[$bucket] = $personal + $board;
        }
        $result['total'] = array_sum($result);
        return $result;
    }
}
