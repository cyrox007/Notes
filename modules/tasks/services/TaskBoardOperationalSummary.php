<?php

declare(strict_types=1);

namespace Modules\Tasks;

use Core\DatabaseManager;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;

/**
 * Краткая сводка только для владельца или руководителя общей доски.
 * Не раскрывает названия задач, исполнителей и другие персональные сведения.
 */
final class TaskBoardOperationalSummary
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /** @return array<string,int|string> */
    public function forBoard(int $userId, string $boardUid, ?DateTimeImmutable $instant = null): array
    {
        if ($userId < 1 || preg_match('/^[a-fA-F0-9]{32}$/', $boardUid) !== 1) {
            throw new InvalidArgumentException('Некорректный идентификатор доски или пользователя');
        }

        $board = $this->db->fetchOne(
            "SELECT b.id
             FROM task_boards b
             INNER JOIN users u ON u.id=:user AND u.is_active=1
                 AND u.account_status='active'
             WHERE b.uid=:uid AND b.is_archived=0
               AND (
                   b.owner_user_id=:owner
                   OR EXISTS (
                       SELECT 1 FROM task_board_members m
                       WHERE m.board_id=b.id AND m.user_id=:manager AND m.role='manager'
                   )
               ) LIMIT 1",
            [':user' => $userId, ':uid' => strtolower($boardUid),
                ':owner' => $userId, ':manager' => $userId]
        );
        if ($board === null) {
            throw new DomainException('Недостаточно прав для просмотра сводки доски', 403);
        }

        $now = ($instant ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $nowSql = $now->format('Y-m-d H:i:s');
        $stale = $now->modify('-7 days')->format('Y-m-d H:i:s');
        $week = $now->modify('-7 days')->format('Y-m-d H:i:s');

        $metrics = $this->db->fetchOne(
            "SELECT
               COUNT(*) AS total,
               COALESCE(SUM(CASE WHEN i.status NOT IN ('completed','cancelled')
                                 THEN 1 ELSE 0 END),0) AS active,
               COALESCE(SUM(CASE WHEN i.status NOT IN ('completed','cancelled')
                                 AND i.due_date IS NOT NULL AND i.due_date<:now_due
                                 THEN 1 ELSE 0 END),0) AS overdue,
               COALESCE(SUM(CASE WHEN i.status IN ('pending','studying','in_progress','review')
                                 AND i.updated_at<:stale
                                 THEN 1 ELSE 0 END),0) AS stagnant,
               COALESCE(SUM(CASE WHEN i.status='completed'
                                 AND i.completed_at>=:week_start
                                 AND i.completed_at<=:week_end
                                 THEN 1 ELSE 0 END),0) AS completed_7d,
               COALESCE(SUM(CASE WHEN i.status NOT IN ('completed','cancelled')
                                 AND NOT EXISTS (
                                     SELECT 1 FROM task_board_assignees a WHERE a.task_id=i.id
                                 ) THEN 1 ELSE 0 END),0) AS unassigned
             FROM task_board_items i
             WHERE i.board_id=:board_id AND i.is_deleted=0 AND i.archived_at IS NULL",
            [':board_id' => (int) $board['id'], ':now_due' => $nowSql,
                ':stale' => $stale, ':week_start' => $week, ':week_end' => $nowSql]
        ) ?? [];

        return [
            'total' => (int) ($metrics['total'] ?? 0),
            'active' => (int) ($metrics['active'] ?? 0),
            'overdue' => (int) ($metrics['overdue'] ?? 0),
            'stagnant' => (int) ($metrics['stagnant'] ?? 0),
            'completed_7d' => (int) ($metrics['completed_7d'] ?? 0),
            'unassigned' => (int) ($metrics['unassigned'] ?? 0),
            'generated_at_utc' => $nowSql,
        ];
    }
}
