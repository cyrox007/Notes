<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;

/**
 * Команда из центра уведомлений: только личная задача, только своё уведомление.
 * Повторы безопасно обрабатываются штатным хранилищем изменяющих команд Tasks.
 */
final class TaskNotificationActionService
{
    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @return array<string,mixed> */
    public function complete(int $userId, int $notificationId): array
    {
        if ($userId < 1 || $notificationId < 1) {
            throw new InvalidArgumentException('Недопустимый пользователь или уведомление');
        }

        $notice = $this->db->fetchOne(
            'SELECT e.source_name, e.event_name, e.payload_json
             FROM notifications n
             JOIN notification_events e ON e.id = n.event_id
             WHERE n.id = :notification AND n.recipient_user_id = :user
             LIMIT 1',
            [':notification' => $notificationId, ':user' => $userId]
        );
        if ($notice === null) {
            throw new DomainException('Уведомление недоступно', 404);
        }

        $uid = self::taskUidFromEvent($notice);
        if ($uid === null) {
            throw new DomainException('Для этого уведомления нет действия по задаче', 422);
        }

        $task = $this->db->fetchOne(
            'SELECT uid, version, status, archived_at
             FROM tasks
             WHERE uid = :uid AND user_id = :user AND is_deleted = 0
             LIMIT 1',
            [':uid' => $uid, ':user' => $userId]
        );
        if ($task === null) {
            throw new DomainException('Задача больше не доступна', 404);
        }
        if ($task['archived_at'] !== null) {
            throw new DomainException('Архивную задачу нельзя завершить из уведомления', 409);
        }
        if ($task['status'] === 'completed') {
            return ['success' => true, 'already_completed' => true, 'task_uid' => $uid];
        }

        $version = (int) $task['version'];
        $commandId = 'notification-task-complete-' . $notificationId;
        return (new TaskVersionMutationService($this->db))->mutateOwned(
            $userId,
            $uid,
            $version,
            $commandId,
            'task.status',
            ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')]
        );
    }

    /** @param array<string,mixed> $event */
    public static function taskUidFromEvent(array $event): ?string
    {
        if (($event['source_name'] ?? null) !== 'tasks'
            || !is_string($event['event_name'] ?? null)
            || !str_starts_with($event['event_name'], 'tasks.')) {
            return null;
        }

        $payload = json_decode((string) ($event['payload_json'] ?? ''), true);
        if (!is_array($payload) || array_is_list($payload)) {
            return null;
        }

        $uid = $payload['task_uid'] ?? null;
        return is_string($uid) && preg_match('/^[a-f0-9]{32}$/D', $uid) === 1 ? $uid : null;
    }
}
