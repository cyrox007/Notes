<?php

declare(strict_types=1);

namespace App\Services\Notifications;

interface NotificationSecretResolver
{
    public function resolve(?string $reference): ?string;
}
