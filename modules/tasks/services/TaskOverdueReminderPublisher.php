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
 * Повторяет напоминание о незавершённой просроченной личной задаче
 * не чаще одного раза за календарный день пользователя.
 * Внешний канал выбирает единая платформа уведомлений.
 */
final class TaskOverdueReminderPublisher
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
            throw new InvalidArgumentException('Размер страницы должен быть в пределах 1–500');
        }

        $instant = ($instant ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
        $cursor = 0;
        $users = 0;
        $published = 0;
        while (true) {
            $recipients = $this->db->fetchAll(
                "SELECT p.user_id,p.timezone
                 FROM task_user_preferences p
                 JOIN task_morning_digest_settings s ON s.user_id=p.user_id AND s.enabled=1
                 JOIN users u ON u.id=p.user_id
                 WHERE p.user_id>:cursor AND u.is_active=1 AND u.account_status='active'
                   AND u.deletion_requested_at IS NULL AND u.anonymized_at IS NULL
                 ORDER BY p.user_id LIMIT " . $pageSize,
                [':cursor' => $cursor]
            );
            if ($recipients === []) {
                break;
            }
            foreach ($recipients as $recipient) {
                $cursor = (int) $recipient['user_id'];
                try {
                    $local = $instant->setTimezone(new DateTimeZone((string) $recipient['timezone']));
                } catch (\Throwable) {
                    continue;
                }
                // Повтор выполняется в 09:00 по местному времени, после утренней сводки.
                if ((int) $local->format('H') !== 9) {
                    continue;
                }
                ++$users;
                $date = $local->format('Y-m-d');
                $this->publishForUser($cursor, $date, $instant, $pageSize, $published);
            }
        }
        return ['users' => $users, 'published' => $published];
    }

    private function publishForUser(
        int $userId,
        string $date,
        DateTimeImmutable $instant,
        int $pageSize,
        int &$published
    ): void {
        $taskId = 0;
        while (true) {
            $tasks = $this->db->fetchAll(
                "SELECT id,uid,title,due_date
                 FROM tasks
                 WHERE user_id=:user AND id>:after_id AND is_deleted=0
                   AND archived_at IS NULL AND due_date IS NOT NULL
                   AND due_date < :today AND status NOT IN ('completed','cancelled')
                 ORDER BY id LIMIT " . $pageSize,
                [':user' => $userId, ':after_id' => $taskId, ':today' => $date . ' 00:00:00']
            );
            if ($tasks === []) {
                return;
            }
            foreach ($tasks as $task) {
                $taskId = (int) $task['id'];
                $uid = (string) $task['uid'];
                // Содержимое задачи не отправляется наружу, только ссылка и общий текст.
                $this->notifications->publish(new NotificationEvent(
                    name: 'tasks.overdue_repeat',
                    version: 1,
                    idempotencyKey: 'tasks:overdue:' . $userId . ':' . $uid . ':' . $date,
                    source: 'tasks',
                    category: 'tasks.reminders',
                    importance: NotificationEvent::IMPORTANCE_NORMAL,
                    title: 'Просроченная задача',
                    body: 'В вашем списке остаётся незавершённая просроченная задача.',
                    recipientUserIds: [$userId],
                    payload: ['task_uid' => $uid, 'date' => $date],
                    targetPath: '/tasks',
                    occurredAt: $instant
                ));
                ++$published;
            }
        }
    }
}
