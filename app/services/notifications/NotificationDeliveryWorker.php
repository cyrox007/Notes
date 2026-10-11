<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once __DIR__ . '/NotificationDeliveryQueue.php';
require_once __DIR__ . '/NotificationDeliveryChannel.php';
require_once __DIR__ . '/NotificationDeliveryResult.php';

use InvalidArgumentException;
use Throwable;

final class NotificationDeliveryWorker
{
    /** @var array<string,NotificationDeliveryChannel> */
    private array $channels = [];

    /**
     * @param iterable<NotificationDeliveryChannel> $channels
     */
    public function __construct(
        private NotificationDeliveryQueue $queue,
        iterable $channels
    ) {
        foreach ($channels as $channel) {
            $name = trim($channel->name());
            if (preg_match('/^[a-z][a-z0-9_.-]{1,31}$/', $name) !== 1) {
                throw new InvalidArgumentException('Имя канала доставки имеет недопустимый формат');
            }
            if (isset($this->channels[$name])) {
                throw new InvalidArgumentException('Канал доставки зарегистрирован повторно: ' . $name);
            }
            $this->channels[$name] = $channel;
        }
    }

    /**
     * @return array{claimed:int,sent:int,retry:int,failed:int}
     */
    public function run(string $workerId, int $limit = 20): array
    {
        $jobs = $this->queue->claimBatch($workerId, $limit);
        $stats = [
            'claimed' => count($jobs),
            'sent' => 0,
            'retry' => 0,
            'failed' => 0,
        ];

        foreach ($jobs as $job) {
            $jobId = (int) ($job['id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }

            if (($job['recipient_eligible'] ?? false) !== true) {
                $this->completeFailure(
                    $job,
                    $workerId,
                    'recipient_ineligible',
                    'Получатель отключён, удаляется или недоступен',
                    false,
                    $stats
                );
                continue;
            }

            if (($job['channel_enabled'] ?? false) !== true) {
                $this->completeFailure(
                    $job,
                    $workerId,
                    'channel_disabled',
                    'Конфигурация канала отключена',
                    false,
                    $stats
                );
                continue;
            }

            $channelName = (string) ($job['channel'] ?? '');
            $channel = $this->channels[$channelName] ?? null;
            if (!$channel instanceof NotificationDeliveryChannel) {
                $this->completeFailure(
                    $job,
                    $workerId,
                    'channel_unavailable',
                    'Адаптер канала доставки недоступен',
                    true,
                    $stats
                );
                continue;
            }

            $result = $this->deliverSafely($channel, $job);
            if ($result->success) {
                $this->queue->markSent($jobId, $workerId, $result->code);
                ++$stats['sent'];
                continue;
            }

            $this->completeFailure(
                $job,
                $workerId,
                $result->code,
                $result->errorSummary,
                $result->retryable,
                $stats
            );
        }

        return $stats;
    }

    /**
     * @param array<string,mixed> $job
     * @param array{claimed:int,sent:int,retry:int,failed:int} $stats
     */
    private function completeFailure(
        array $job,
        string $workerId,
        string $resultCode,
        ?string $errorSummary,
        bool $retryable,
        array &$stats
    ): void {
        $jobId = (int) ($job['id'] ?? 0);
        $this->queue->markFailed($jobId, $workerId, $resultCode, $errorSummary, $retryable);

        $attemptNo = max(1, (int) ($job['attempt_no'] ?? 1));
        $maxAttempts = max(1, (int) ($job['max_attempts'] ?? PHP_INT_MAX));
        $state = $retryable && $attemptNo < $maxAttempts ? 'retry' : 'failed';
        ++$stats[$state];
    }

    /** @param array<string,mixed> $job */
    private function deliverSafely(NotificationDeliveryChannel $channel, array $job): NotificationDeliveryResult
    {
        try {
            return $channel->deliver($job);
        } catch (Throwable $e) {
            return NotificationDeliveryResult::failed(
                'channel_exception',
                'Адаптер канала завершился исключением типа ' . $e::class,
                true
            );
        }
    }
}
