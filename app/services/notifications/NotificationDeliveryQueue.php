<?php

declare(strict_types=1);

namespace App\Services\Notifications;

interface NotificationDeliveryQueue
{
    /**
     * @param array<int,int> $notificationIdsByUser ключ — user_id, значение — notification_id
     * @return list<int>
     */
    public function enqueueForEvent(NotificationEvent $event, array $notificationIdsByUser): array;

    /**
     * @return list<array<string,mixed>>
     */
    public function claimBatch(string $workerId, int $limit = 20, int $lockTimeoutSeconds = 300): array;

    public function markSent(int $jobId, string $workerId, string $resultCode = 'sent'): void;

    public function markFailed(
        int $jobId,
        string $workerId,
        string $resultCode,
        ?string $errorSummary = null,
        bool $retryable = true
    ): void;
}
