<?php

declare(strict_types=1);

namespace App\Services\Notifications;

interface NotificationStore
{
    /**
     * @return array{event_id:int, notification_ids:list<int>}
     */
    public function persist(NotificationEvent $event): array;
}
