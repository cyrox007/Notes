<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationEvent.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationDeliveryQueue.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationDeliveryChannel.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationDeliveryResult.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationDeliveryWorker.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationRetryPolicy.php';

use App\Services\Notifications\NotificationDeliveryChannel;
use App\Services\Notifications\NotificationDeliveryQueue;
use App\Services\Notifications\NotificationDeliveryResult;
use App\Services\Notifications\NotificationDeliveryWorker;
use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationRetryPolicy;
use DateTimeImmutable;
use DateTimeZone;

function notificationDeliveryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class InMemoryNotificationDeliveryQueue implements NotificationDeliveryQueue
{
    /** @var list<array<string,mixed>> */
    public array $jobs;
    /** @var list<array<string,mixed>> */
    public array $results = [];

    /** @param list<array<string,mixed>> $jobs */
    public function __construct(array $jobs)
    {
        $this->jobs = $jobs;
    }

    public function enqueueForEvent(NotificationEvent $event, array $notificationIdsByUser): array
    {
        return [];
    }

    public function claimBatch(string $workerId, int $limit = 20, int $lockTimeoutSeconds = 300): array
    {
        return array_slice($this->jobs, 0, $limit);
    }

    public function markSent(int $jobId, string $workerId, string $resultCode = 'sent'): void
    {
        $this->results[] = [
            'id' => $jobId,
            'state' => 'sent',
            'code' => $resultCode,
            'retryable' => false,
        ];
    }

    public function markFailed(
        int $jobId,
        string $workerId,
        string $resultCode,
        ?string $errorSummary = null,
        bool $retryable = true
    ): void {
        $this->results[] = [
            'id' => $jobId,
            'state' => $retryable ? 'retry' : 'failed',
            'code' => $resultCode,
            'retryable' => $retryable,
            'error_summary' => $errorSummary,
        ];
    }
}

final class TestEmailNotificationChannel implements NotificationDeliveryChannel
{
    public function name(): string
    {
        return 'email';
    }

    public function deliver(array $job): NotificationDeliveryResult
    {
        if ((int) ($job['id'] ?? 0) === 5) {
            return NotificationDeliveryResult::failed(
                'smtp_temporary',
                'Временная ошибка SMTP',
                true
            );
        }

        return NotificationDeliveryResult::sent('smtp_sent');
    }
}

$policy = new NotificationRetryPolicy(60, 3600);
notificationDeliveryAssert($policy->delaySeconds(1) === 60, 'Первая повторная попытка должна ждать 60 секунд');
notificationDeliveryAssert($policy->delaySeconds(2) === 120, 'Вторая повторная попытка должна удваивать задержку');
notificationDeliveryAssert($policy->delaySeconds(10) === 3600, 'Backoff должен ограничиваться максимальной задержкой');
$fixedNow = new DateTimeImmutable('2026-10-11 10:00:00', new DateTimeZone('UTC'));
notificationDeliveryAssert(
    $policy->nextAttemptAt(3, $fixedNow)->format('Y-m-d H:i:s') === '2026-10-11 10:04:00',
    'Время следующей попытки должно рассчитываться детерминированно'
);

$queue = new InMemoryNotificationDeliveryQueue([
    ['id' => 1, 'channel' => 'email', 'recipient_eligible' => true, 'channel_enabled' => true],
    ['id' => 2, 'channel' => 'email', 'recipient_eligible' => false, 'channel_enabled' => true],
    ['id' => 3, 'channel' => 'sms', 'recipient_eligible' => true, 'channel_enabled' => false],
    ['id' => 4, 'channel' => 'webhook', 'recipient_eligible' => true, 'channel_enabled' => true],
    ['id' => 5, 'channel' => 'email', 'recipient_eligible' => true, 'channel_enabled' => true],
]);
$worker = new NotificationDeliveryWorker($queue, [new TestEmailNotificationChannel()]);
$stats = $worker->run('test-worker-01', 20);

notificationDeliveryAssert($stats === [
    'claimed' => 5,
    'sent' => 1,
    'retry' => 2,
    'failed' => 2,
], 'Worker должен корректно разделять успешные, повторные и окончательные результаты');

$resultsById = [];
foreach ($queue->results as $result) {
    $resultsById[(int) $result['id']] = $result;
}
notificationDeliveryAssert(($resultsById[1]['code'] ?? null) === 'smtp_sent', 'Успешная доставка должна сохранять код канала');
notificationDeliveryAssert(($resultsById[2]['code'] ?? null) === 'recipient_ineligible', 'Отключённый получатель не должен доставляться');
notificationDeliveryAssert(($resultsById[2]['retryable'] ?? true) === false, 'Недопустимый получатель не должен уходить в retry');
notificationDeliveryAssert(($resultsById[3]['code'] ?? null) === 'channel_disabled', 'Отключённый канал не должен использоваться');
notificationDeliveryAssert(($resultsById[4]['code'] ?? null) === 'channel_unavailable', 'Отсутствующий адаптер должен давать контролируемый retry');
notificationDeliveryAssert(($resultsById[5]['code'] ?? null) === 'smtp_temporary', 'Временная ошибка канала должна сохранять собственный код');

$root = dirname(__DIR__, 2);
$resolverSource = file_get_contents($root . '/app/services/notifications/DatabaseNotificationRecipientResolver.php');
$queueSource = file_get_contents($root . '/app/services/notifications/DatabaseNotificationDeliveryQueue.php');
$storeSource = file_get_contents($root . '/app/services/notifications/DatabaseNotificationStore.php');
$migration = file_get_contents($root . '/database/migrations/20261010_notifications_foundation.sql');

foreach ([
    'is_active = 1',
    "account_status = \\'active\\'",
    'deletion_requested_at IS NULL',
    'anonymized_at IS NULL',
] as $fragment) {
    notificationDeliveryAssert(
        is_string($resolverSource) && str_contains($resolverSource, $fragment),
        'Resolver получателей обязан проверять: ' . $fragment
    );
}

foreach ([
    'FOR UPDATE',
    'worker_interrupted',
    'attempts = attempts + 1',
    "'user:' . $userId",
    'recipient_eligible',
    'channel_enabled',
] as $fragment) {
    notificationDeliveryAssert(
        is_string($queueSource) && str_contains($queueSource, $fragment),
        'Очередь доставки обязана сохранять инвариант: ' . $fragment
    );
}

$enqueuePosition = is_string($storeSource) ? strpos($storeSource, 'enqueueForEvent') : false;
$commitPosition = is_string($storeSource) ? strpos($storeSource, 'endTransaction(true)') : false;
notificationDeliveryAssert(
    is_int($enqueuePosition) && is_int($commitPosition) && $enqueuePosition < $commitPosition,
    'Внешние задания должны ставиться до фиксации транзакции публикации'
);

notificationDeliveryAssert(
    is_string($migration) && str_contains($migration, '`owner_scope` INT AS (IFNULL(`owner_user_id`, 0)) STORED'),
    'Глобальная конфигурация канала должна иметь нормализованную область уникальности'
);
notificationDeliveryAssert(
    is_string($migration) && str_contains($migration, '(`channel`, `owner_scope`, `label`)'),
    'Уникальность конфигурации канала должна учитывать нормализованную область владельца'
);

fwrite(STDOUT, "[OK] очередь и отказоустойчивость уведомлений 1.1.0\n");
