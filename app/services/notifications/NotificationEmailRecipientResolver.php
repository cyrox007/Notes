<?php

declare(strict_types=1);

namespace App\Services\Notifications;

interface NotificationEmailRecipientResolver
{
    public function emailForUser(int $userId): ?string;
}
