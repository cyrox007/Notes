<?php

declare(strict_types=1);

namespace App\Services\Notifications;

interface NotificationDeliveryChannel
{
    public function name(): string;

    /** @param array<string,mixed> $job */
    public function deliver(array $job): NotificationDeliveryResult;
}
