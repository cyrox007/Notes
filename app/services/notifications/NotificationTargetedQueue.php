<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once __DIR__ . '/NotificationDeliveryQueue.php';

/**
 * Дополнительный контракт точечного захвата задания.
 * Обычные обработчики продолжают использовать claimBatch без изменений.
 */
interface NotificationTargetedQueue extends NotificationDeliveryQueue
{
    /** @return list<array<string,mixed>> */
    public function claimJob(string $workerId, int $jobId): array;
}
