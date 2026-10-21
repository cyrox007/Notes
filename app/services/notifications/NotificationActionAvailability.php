<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Core\DatabaseManager;

/**
 * Служебная проверка доступных действий без передачи внутреннего
 * содержимого события через API центра уведомлений.
 */
final class NotificationActionAvailability
{
    public function __construct(private DatabaseManager $db)
    {
    }

    /**
     * @param list<array<string,mixed>> $page
     * @return array<int,list<string>>
     */
    public function forUser(int $userId, array $page): array
    {
        if ($userId < 1 || $page === []) {
            return [];
        }

        $ids = [];
        foreach ($page as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $params = [':user' => $userId];
        $placeholders = [];
        foreach (array_values($ids) as $index => $id) {
            $key = ':notification_' . $index;
            $params[$key] = $id;
            $placeholders[] = $key;
        }
        $rows = $this->db->fetchAll(
            'SELECT n.id, e.event_name, e.payload_json
             FROM notifications n
             JOIN notification_events e ON e.id = n.event_id
             WHERE n.recipient_user_id = :user
               AND n.id IN (' . implode(', ', $placeholders) . ')
               AND e.source_name = :source',
            $params + [':source' => 'tasks']
        );

        $actions = [];
        foreach ($rows as $row) {
            $eventName = (string) ($row['event_name'] ?? '');
            if (!str_starts_with($eventName, 'tasks.')) {
                continue;
            }
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload) || array_is_list($payload)) {
                continue;
            }
            $uid = $payload['task_uid'] ?? null;
            if (is_string($uid) && preg_match('/^[a-f0-9]{32}$/D', $uid) === 1) {
                $actions[(int) $row['id']] = ['complete_task'];
            }
        }

        return $actions;
    }
}
