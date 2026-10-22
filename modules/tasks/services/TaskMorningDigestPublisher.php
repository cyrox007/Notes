<?php

declare(strict_types=1);

namespace Modules\Tasks;

use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationService;
use Core\DatabaseManager;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Публикует утреннюю сводку один раз на локальный календарный день.
 * Повторные запуски не создают повторных событий благодаря ключу события.
 */
final class TaskMorningDigestPublisher
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly NotificationService $notifications
    ) {
    }

    /** @return array{checked:int,published:int,skipped:int} */
    public function run(?DateTimeImmutable $instant = null, int $limit = 100, int $afterUserId = 0): array
    {
        if ($limit < 1 || $limit > 500 || $afterUserId < 0) {
            throw new InvalidArgumentException('Количество получателей должно быть от 1 до 500');
        }

        $instant = ($instant ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
        $rows = $this->db->fetchAll(
            "SELECT p.user_id,p.timezone
             FROM task_user_preferences p
             INNER JOIN task_morning_digest_settings d ON d.user_id=p.user_id AND d.enabled=1
             INNER JOIN users u ON u.id=p.user_id
             WHERE p.user_id > :after_user AND u.is_active=1 AND u.account_status='active'
               AND u.deletion_requested_at IS NULL AND u.anonymized_at IS NULL
             ORDER BY p.user_id",
            [':after_user' => $afterUserId]
        );

        $checked = 0;
        $published = 0;
        $skipped = 0;
        $queue = new TaskAssigneeQueueService($this->db);
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            try {
                $zone = new DateTimeZone((string) $row['timezone']);
            } catch (\Throwable) {
                ++$skipped;
                continue;
            }
            $local = $instant->setTimezone($zone);
            // Короткое окно исключает устаревшие сводки при позднем восстановлении сервера.
            if ((int) $local->format('H') !== 8) {
                continue;
            }
            if ($checked >= $limit) {
                break;
            }
            ++$checked;
            $summary = $queue->forUser($userId, $local)['summary'];
            if ($summary['total'] === 0) {
                ++$skipped;
                continue;
            }
            $date = $local->format('Y-m-d');
            $this->notifications->publish(new NotificationEvent(
                name: 'tasks.morning_digest',
                version: 1,
                idempotencyKey: 'tasks:morning:' . $userId . ':' . $date,
                source: 'tasks',
                category: 'tasks.reminders',
                importance: NotificationEvent::IMPORTANCE_NORMAL,
                title: 'Задачи на ' . $local->format('d.m.Y'),
                body: sprintf(
                    'Просрочено: %d. Сегодня: %d. Завтра: %d.',
                    $summary['overdue'],
                    $summary['today'],
                    $summary['tomorrow']
                ),
                recipientUserIds: [$userId],
                payload: ['date' => $date, 'timezone' => $zone->getName(), 'summary' => $summary],
                targetPath: '/tasks',
                occurredAt: $instant
            ));
            ++$published;
        }
        return ['checked' => $checked, 'published' => $published, 'skipped' => $skipped];
    }
}
