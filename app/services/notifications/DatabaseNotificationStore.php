<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once dirname(__DIR__, 3) . '/core/DatabaseManager.php';
require_once __DIR__ . '/NotificationEvent.php';
require_once __DIR__ . '/NotificationStore.php';
require_once __DIR__ . '/NotificationRecipientResolver.php';
require_once __DIR__ . '/DatabaseNotificationRecipientResolver.php';
require_once __DIR__ . '/NotificationDeliveryQueue.php';
require_once __DIR__ . '/DatabaseNotificationDeliveryQueue.php';

use Core\DatabaseManager;
use Throwable;

final class DatabaseNotificationStore implements NotificationStore
{
    private DatabaseManager $db;
    private NotificationRecipientResolver $recipientResolver;
    private NotificationDeliveryQueue $deliveryQueue;

    public function __construct(
        ?DatabaseManager $db = null,
        ?NotificationRecipientResolver $recipientResolver = null,
        ?NotificationDeliveryQueue $deliveryQueue = null
    ) {
        $this->db = $db ?? DatabaseManager::getInstance();
        $this->recipientResolver = $recipientResolver ?? new DatabaseNotificationRecipientResolver($this->db);
        $this->deliveryQueue = $deliveryQueue ?? new DatabaseNotificationDeliveryQueue($this->db);
    }

    public function persist(NotificationEvent $event): array
    {
        $this->db->beginTransaction();

        try {
            $eventId = $this->persistEvent($event);
            $eligibleUserIds = $this->recipientResolver->eligibleUserIds($event->recipientUserIds);
            $notificationIdsByUser = $this->persistRecipients($eventId, $event, $eligibleUserIds);
            $this->deliveryQueue->enqueueForEvent($event, $notificationIdsByUser);
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }

        return [
            'event_id' => $eventId,
            'notification_ids' => array_values($notificationIdsByUser),
        ];
    }

    private function persistEvent(NotificationEvent $event): int
    {
        $payload = json_encode(
            $event->payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $this->db->execute(
            'INSERT INTO notification_events (
                event_name,
                event_version,
                idempotency_key,
                source_name,
                category,
                importance,
                occurred_at,
                payload_json,
                created_at
             ) VALUES (
                :event_name,
                :event_version,
                :idempotency_key,
                :source_name,
                :category,
                :importance,
                :occurred_at,
                :payload_json,
                NOW()
             )
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            [
                ':event_name' => $event->name,
                ':event_version' => $event->version,
                ':idempotency_key' => $event->idempotencyKey,
                ':source_name' => $event->source,
                ':category' => $event->category,
                ':importance' => $event->importance,
                ':occurred_at' => $event->occurredAtSql(),
                ':payload_json' => $payload,
            ]
        );

        return (int) $this->db->getPdo()->lastInsertId();
    }

    /**
     * @param list<int> $eligibleUserIds
     * @return array<int,int> ключ — user_id, значение — notification_id
     */
    private function persistRecipients(int $eventId, NotificationEvent $event, array $eligibleUserIds): array
    {
        $notificationIds = [];

        foreach ($eligibleUserIds as $userId) {
            $this->db->execute(
                'INSERT INTO notifications (
                    event_id,
                    recipient_user_id,
                    title,
                    body,
                    target_path,
                    created_at
                 ) VALUES (
                    :event_id,
                    :recipient_user_id,
                    :title,
                    :body,
                    :target_path,
                    NOW()
                 )
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                [
                    ':event_id' => $eventId,
                    ':recipient_user_id' => $userId,
                    ':title' => $event->title,
                    ':body' => $event->body,
                    ':target_path' => $event->targetPath,
                ]
            );

            $notificationIds[$userId] = (int) $this->db->getPdo()->lastInsertId();
        }

        return $notificationIds;
    }
}
