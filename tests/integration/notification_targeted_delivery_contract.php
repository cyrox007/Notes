<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationEvent.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationTargetedQueue.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationDeliveryChannel.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationDeliveryResult.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationDeliveryWorker.php';

use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationTargetedQueue;
use App\Services\Notifications\NotificationDeliveryChannel;
use App\Services\Notifications\NotificationDeliveryResult;
use App\Services\Notifications\NotificationDeliveryWorker;

final class NotificationTargetedTestQueue implements NotificationTargetedQueue
{
    /** @var list<array<string,mixed>> */
    public array $pending;
    /** @var list<int> */
    public array $delivered = [];

    public function __construct()
    {
        $this->pending = [
            ['id' => 21, 'channel' => 'email', 'recipient_eligible' => true, 'channel_enabled' => true],
            ['id' => 22, 'channel' => 'email', 'recipient_eligible' => true, 'channel_enabled' => true],
        ];
    }

    public function enqueueForEvent(NotificationEvent $event, array $notificationIdsByUser): array
    {
        return [];
    }

    public function claimBatch(string $workerId, int $limit = 20, int $lockTimeoutSeconds = 300): array
    {
        return array_slice($this->pending, 0, $limit);
    }

    public function claimJob(string $workerId, int $jobId): array
    {
        return array_values(array_filter(
            $this->pending,
            static fn (array $job): bool => (int) $job['id'] === $jobId
        ));
    }

    public function markSent(int $jobId, string $workerId, string $resultCode = 'sent'): void
    {
        $this->delivered[] = $jobId;
        $this->pending = array_values(array_filter(
            $this->pending,
            static fn (array $job): bool => (int) $job['id'] !== $jobId
        ));
    }

    public function markFailed(
        int $jobId,
        string $workerId,
        string $resultCode,
        ?string $errorSummary = null,
        bool $retryable = true
    ): void {
        throw new RuntimeException('В тестовом канале ошибки не ожидаются');
    }
}

final class NotificationTargetedTestChannel implements NotificationDeliveryChannel
{
    public function name(): string
    {
        return 'email';
    }

    public function deliver(array $job): NotificationDeliveryResult
    {
        return NotificationDeliveryResult::sent('smtp_accepted');
    }
}

$queue = new NotificationTargetedTestQueue();
$worker = new NotificationDeliveryWorker($queue, [new NotificationTargetedTestChannel()]);
$stats = $worker->runJob('notification-test-01', 22);

if ($stats !== ['claimed' => 1, 'sent' => 1, 'retry' => 0, 'failed' => 0]
    || $queue->delivered !== [22] || count($queue->pending) !== 1
    || (int) $queue->pending[0]['id'] !== 21) {
    throw new RuntimeException('Точечная отправка не должна затрагивать старое задание в общей очереди');
}

$missing = $worker->runJob('notification-test-02', 999);
if ($missing['claimed'] !== 0 || $queue->delivered !== [22]) {
    throw new RuntimeException('Отсутствующее задание не должно вызывать постороннюю отправку');
}

$normal = $worker->run('notification-background-03', 1);
if ($normal['sent'] !== 1 || $queue->delivered !== [22, 21]) {
    throw new RuntimeException('Обычный обработчик должен продолжать обслуживать общую очередь');
}

$source = file_get_contents(dirname(__DIR__, 2) . '/app/services/notifications/DatabaseNotificationDeliveryQueue.php');
if (!is_string($source)
    || !str_contains($source, 'AND j.id = :target_job_id')
    || !str_contains($source, "':target_job_id' => \$jobId")
    || !str_contains($source, 'FOR UPDATE')) {
    throw new RuntimeException('Точечный захват в БД должен блокировать только выбранное задание по параметру');
}

echo "Точечная пробная отправка не трогает общую очередь уведомлений\n";
