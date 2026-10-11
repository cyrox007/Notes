<?php

declare(strict_types=1);

namespace App\Services\Notifications;

interface NotificationRecipientResolver
{
    /**
     * @param list<int> $requestedUserIds
     * @return list<int>
     */
    public function eligibleUserIds(array $requestedUserIds): array;
}
