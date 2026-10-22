<?php

declare(strict_types=1);

namespace Modules\Tasks;

require_once __DIR__ . '/TaskReminderRepeatPolicy.php';

use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationService;
use Core\DatabaseManager;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Повторы по назначенным командным задачам только при действующем доступе к доске. */
final class TaskBoardOverdueReminderPublisher
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly NotificationService $notifications
    ) {
    }

    /** @return array{users:int,published:int} */
    public function run(?DateTimeImmutable $instant = null, int $pageSize = 100): array
    {
        if ($pageSize < 1 || $pageSize > 500) {
            throw new InvalidArgumentException('Размер порции должен быть в пределах 1–500');
        }
        $instant = ($instant ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
        $cursor = 0;
        $users = 0;
        $published = 0;
        while (true) {
            $rows = $this->db->fetchAll(
                "SELECT p.user_id,p.timezone,
                        COALESCE(s.repeat_interval_days,s.enabled) AS repeat_interval_days
                 FROM task_user_preferences p
                 JOIN task_morning_digest_settings s ON s.user_id=p.user_id
                 JOIN users u ON u.id=p.user_id
                 WHERE p.user_id>:cursor AND COALESCE(s.repeat_interval_days,s.enabled)>0
                   AND u.is_active=1 AND u.account_status='active'
                   AND u.deletion_requested_at IS NULL AND u.anonymized_at IS NULL
                 ORDER BY p.user_id LIMIT " . $pageSize,
                [':cursor' => $cursor]
            );
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $cursor = (int) $row['user_id'];
                try {
                    $local = $instant->setTimezone(new DateTimeZone((string) $row['timezone']));
                } catch (\Throwable) {
                    continue;
                }
                if ((int) $local->format('H') !== 9) {
                    continue;
                }
                $interval = (int) $row['repeat_interval_days'];
                if (!in_array($interval, [1, 3, 7], true)) {
                    continue;
                }
                ++$users;
                $lastId = 0;
                while (true) {
                    $tasks = $this->db->fetchAll(
                        "SELECT i.id,i.uid,i.due_date
                         FROM task_board_assignees a
                         JOIN task_board_items i ON i.id=a.task_id
                         JOIN task_boards b ON b.id=i.board_id
                         LEFT JOIN task_board_members m ON m.board_id=b.id AND m.user_id=:member
                         WHERE a.user_id=:assignee AND i.id>:after_id
                           AND i.is_deleted=0 AND i.archived_at IS NULL
                           AND b.is_archived=0 AND i.due_date IS NOT NULL
                           AND i.due_date<:today AND i.status NOT IN ('completed','cancelled')
                           AND (b.owner_user_id=:owner OR m.user_id IS NOT NULL OR b.audience='all_active')
                         ORDER BY i.id LIMIT " . $pageSize,
                        [
                            ':member' => $cursor, ':assignee' => $cursor, ':owner' => $cursor,
                            ':after_id' => $lastId, ':today' => $local->format('Y-m-d') . ' 00:00:00'
                        ]
                    );
                    if ($tasks === []) {
                        break;
                    }
                    foreach ($tasks as $task) {
                        $lastId = (int) $task['id'];
                        $uid = (string) $task['uid'];
                        $day = $local->format('Y-m-d');
                        if (!TaskReminderRepeatPolicy::due((string) $task['due_date'], $day, $interval)) {
                            continue;
                        }
                        $this->notifications->publish(new NotificationEvent(
                            name: 'tasks.board_overdue_repeat',
                            version: 1,
                            idempotencyKey: 'tasks:board-overdue:' . $cursor . ':' . $uid . ':' . $day,
                            source: 'tasks',
                            category: 'tasks.reminders',
                            importance: NotificationEvent::IMPORTANCE_NORMAL,
                            title: 'Просроченная командная задача',
                            body: 'Вам назначена незавершённая просроченная задача.',
                            recipientUserIds: [$cursor],
                            payload: ['task_uid' => $uid, 'date' => $day],
                            targetPath: '/tasks/boards',
                            occurredAt: $instant
                        ));
                        ++$published;
                    }
                }
            }
        }
        return ['users' => $users, 'published' => $published];
    }
}
