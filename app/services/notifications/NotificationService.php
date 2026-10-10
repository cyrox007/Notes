<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once __DIR__ . '/NotificationEvent.php';
require_once __DIR__ . '/NotificationStore.php';

final class NotificationService
{
    private NotificationStore $store;

    public function __construct(?NotificationStore $store = null)
    {
        if ($store !== null) {
            $this->store = $store;
            return;
        }

        require_once __DIR__ . '/DatabaseNotificationStore.php';
        $this->store = new DatabaseNotificationStore();
    }

    /**
     * Основная точка публикации семантического события.
     * Источник события не выбирает внешний канал доставки.
     *
     * @return array{event_id:int, notification_ids:list<int>}
     */
    public function publish(NotificationEvent $event): array
    {
        return $this->store->persist($event);
    }
}
