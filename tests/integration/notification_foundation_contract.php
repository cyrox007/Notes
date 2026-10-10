<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationEvent.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationStore.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationService.php';

use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\NotificationStore;

function notificationAssert(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

final class InMemoryNotificationStore implements NotificationStore
{
    /** @var array<string, int> */
    private array $eventIds = [];
    /** @var array<string, int> */
    private array $notificationIds = [];
    private int $nextEventId = 1;
    private int $nextNotificationId = 1;

    public function persist(NotificationEvent $event): array
    {
        $key = $event->name . ':' . $event->idempotencyKey;
        $eventId = $this->eventIds[$key] ??= $this->nextEventId++;
        $ids = [];

        foreach ($event->recipientUserIds as $userId) {
            $recipientKey = $eventId . ':' . $userId;
            $ids[] = $this->notificationIds[$recipientKey] ??= $this->nextNotificationId++;
        }

        return ['event_id' => $eventId, 'notification_ids' => $ids];
    }
}

$event = new NotificationEvent(
    name: 'calendar.reminder_due',
    version: 1,
    idempotencyKey: 'calendar.reminder:42:2026-10-10T08:00:00Z',
    source: 'calendar',
    category: 'calendar.reminders',
    importance: NotificationEvent::IMPORTANCE_HIGH,
    title: 'Напоминание календаря',
    body: 'Событие скоро начнётся',
    recipientUserIds: [7, 7, 9],
    payload: ['calendar_event_id' => 42],
    targetPath: '/calendar/events/42'
);

notificationAssert($event->recipientUserIds === [7, 9], 'Получатели должны дедуплицироваться');

$service = new NotificationService(new InMemoryNotificationStore());
$first = $service->publish($event);
$second = $service->publish($event);
notificationAssert($first === $second, 'Повтор одной идемпотентной публикации не должен создавать новые записи');

$invalidTargetRejected = false;
try {
    new NotificationEvent(
        name: 'calendar.reminder_due',
        version: 1,
        idempotencyKey: 'unsafe-target',
        source: 'calendar',
        category: 'calendar.reminders',
        importance: NotificationEvent::IMPORTANCE_NORMAL,
        title: 'Проверка',
        body: 'Проверка',
        recipientUserIds: [1],
        targetPath: 'https://example.test/open-redirect'
    );
} catch (InvalidArgumentException) {
    $invalidTargetRejected = true;
}
notificationAssert($invalidTargetRejected, 'Внешний URL не должен приниматься как внутренняя ссылка уведомления');

$migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261010_notifications_foundation.sql');
notificationAssert(is_string($migration), 'Миграция уведомлений должна существовать');
foreach ([
    'notification_events',
    'notifications',
    'notification_subscriptions',
    'notification_channel_configs',
    'notification_delivery_jobs',
    'notification_delivery_attempts',
    'uq_notification_event_idempotency',
    'uq_notification_delivery_job',
] as $requiredFragment) {
    notificationAssert(str_contains($migration, $requiredFragment), 'В миграции отсутствует ' . $requiredFragment);
}

fwrite(STDOUT, "[OK] базовый контракт уведомлений 1.1.0\n");
