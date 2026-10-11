<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once dirname(__DIR__, 3) . '/core/DatabaseManager.php';
require_once __DIR__ . '/NotificationEvent.php';
require_once __DIR__ . '/NotificationDeliveryQueue.php';
require_once __DIR__ . '/NotificationRetryPolicy.php';

use Core\DatabaseManager;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class DatabaseNotificationDeliveryQueue implements NotificationDeliveryQueue
{
    /** @var array<string,string> */
    private const SUBSCRIPTION_FLAGS = [
        'email' => 'email_enabled',
        'browser' => 'browser_enabled',
        'telegram' => 'telegram_enabled',
        'webhook' => 'webhook_enabled',
        'sms' => 'sms_enabled',
    ];

    private DatabaseManager $db;
    private NotificationRetryPolicy $retryPolicy;

    public function __construct(?DatabaseManager $db = null, ?NotificationRetryPolicy $retryPolicy = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance();
        $this->retryPolicy = $retryPolicy ?? new NotificationRetryPolicy();
    }

    public function enqueueForEvent(NotificationEvent $event, array $notificationIdsByUser): array
    {
        $notificationIdsByUser = $this->normalizeNotificationMap($notificationIdsByUser);
        if ($notificationIdsByUser === []) {
            return [];
        }

        $channelsByUser = $this->subscribedChannels(array_keys($notificationIdsByUser), $event->category);
        if ($channelsByUser === []) {
            return [];
        }

        $configs = $this->effectiveConfigs($channelsByUser);
        $jobIds = [];
        $now = $this->nowSql();

        foreach ($channelsByUser as $userId => $channels) {
            $notificationId = $notificationIdsByUser[$userId] ?? null;
            if (!is_int($notificationId)) {
                continue;
            }

            foreach ($channels as $channel) {
                $configId = $configs[$userId][$channel] ?? $configs[0][$channel] ?? null;
                if (!is_int($configId)) {
                    continue;
                }

                $this->db->execute(
                    'INSERT INTO notification_delivery_jobs (
                        notification_id,
                        channel_config_id,
                        channel,
                        recipient_ref,
                        state,
                        attempts,
                        max_attempts,
                        next_attempt_at,
                        created_at,
                        updated_at
                     ) VALUES (
                        :notification_id,
                        :channel_config_id,
                        :channel,
                        :recipient_ref,
                        \'pending\',
                        0,
                        5,
                        :next_attempt_at,
                        :created_at,
                        :updated_at
                     )
                     ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                    [
                        ':notification_id' => $notificationId,
                        ':channel_config_id' => $configId,
                        ':channel' => $channel,
                        ':recipient_ref' => 'user:' . $userId,
                        ':next_attempt_at' => $now,
                        ':created_at' => $now,
                        ':updated_at' => $now,
                    ]
                );

                $jobIds[] = (int) $this->db->getPdo()->lastInsertId();
            }
        }

        return array_values(array_unique($jobIds));
    }

    public function claimBatch(string $workerId, int $limit = 20, int $lockTimeoutSeconds = 300): array
    {
        $workerId = $this->validatedWorkerId($workerId);
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Размер пакета worker должен быть в диапазоне 1..100');
        }
        if ($lockTimeoutSeconds < 30 || $lockTimeoutSeconds > 3600) {
            throw new InvalidArgumentException('Таймаут блокировки worker должен быть в диапазоне 30..3600 секунд');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowSql = $now->format('Y-m-d H:i:s');
        $staleBefore = $now->modify('-' . $lockTimeoutSeconds . ' seconds')->format('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $rows = $this->db->fetchAll(
                'SELECT
                    j.id,
                    j.channel,
                    j.recipient_ref,
                    j.state,
                    j.attempts,
                    j.max_attempts,
                    j.next_attempt_at,
                    n.recipient_user_id,
                    n.title,
                    n.body,
                    n.target_path,
                    e.event_name,
                    e.event_version,
                    e.source_name,
                    e.category,
                    e.importance,
                    e.occurred_at,
                    e.payload_json,
                    c.id AS channel_config_id,
                    c.enabled AS channel_enabled,
                    c.config_json,
                    c.secret_ref,
                    CASE
                        WHEN u.id IS NOT NULL
                         AND u.is_active = 1
                         AND u.account_status = \'active\'
                         AND u.deletion_requested_at IS NULL
                         AND u.anonymized_at IS NULL
                        THEN 1 ELSE 0
                    END AS recipient_eligible
                 FROM notification_delivery_jobs j
                 INNER JOIN notifications n ON n.id = j.notification_id
                 INNER JOIN notification_events e ON e.id = n.event_id
                 INNER JOIN notification_channel_configs c ON c.id = j.channel_config_id
                 LEFT JOIN users u ON u.id = n.recipient_user_id
                 WHERE (
                    (j.state IN (\'pending\', \'retry\') AND j.next_attempt_at <= :now)
                    OR (j.state = \'processing\' AND j.locked_at IS NOT NULL AND j.locked_at <= :stale_before)
                 )
                 ORDER BY j.next_attempt_at ASC, j.id ASC
                 LIMIT ' . $limit . '
                 FOR UPDATE',
                [
                    ':now' => $nowSql,
                    ':stale_before' => $staleBefore,
                ]
            );

            foreach ($rows as &$row) {
                $jobId = (int) ($row['id'] ?? 0);
                $previousState = (string) ($row['state'] ?? '');
                $previousAttempts = (int) ($row['attempts'] ?? 0);

                if ($previousState === 'processing' && $previousAttempts > 0) {
                    $this->recordInterruptedAttempt($jobId, $previousAttempts, $nowSql);
                }

                $this->db->execute(
                    'UPDATE notification_delivery_jobs
                     SET state = \'processing\',
                         attempts = attempts + 1,
                         locked_at = :locked_at,
                         locked_by = :locked_by,
                         updated_at = :updated_at
                     WHERE id = :id',
                    [
                        ':locked_at' => $nowSql,
                        ':locked_by' => $workerId,
                        ':updated_at' => $nowSql,
                        ':id' => $jobId,
                    ]
                );

                $row['attempt_no'] = $previousAttempts + 1;
                $row['recipient_eligible'] = (int) ($row['recipient_eligible'] ?? 0) === 1;
                $row['channel_enabled'] = (int) ($row['channel_enabled'] ?? 0) === 1;
                $row['locked_by'] = $workerId;
                $row['state'] = 'processing';
            }
            unset($row);

            $this->db->endTransaction(true);
            return $rows;
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    public function markSent(int $jobId, string $workerId, string $resultCode = 'sent'): void
    {
        $workerId = $this->validatedWorkerId($workerId);
        $resultCode = $this->validatedResultCode($resultCode);
        $now = $this->nowSql();

        $this->db->beginTransaction();
        try {
            $job = $this->lockedJob($jobId, $workerId);
            $attemptNo = (int) $job['attempts'];

            $this->recordAttempt($jobId, $attemptNo, 'sent', $resultCode, null, $now);
            $this->db->execute(
                'UPDATE notification_delivery_jobs
                 SET state = \'sent\',
                     last_result_code = :result_code,
                     sent_at = :sent_at,
                     locked_at = NULL,
                     locked_by = NULL,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    ':result_code' => $resultCode,
                    ':sent_at' => $now,
                    ':updated_at' => $now,
                    ':id' => $jobId,
                ]
            );
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    public function markFailed(
        int $jobId,
        string $workerId,
        string $resultCode,
        ?string $errorSummary = null,
        bool $retryable = true
    ): void {
        $workerId = $this->validatedWorkerId($workerId);
        $resultCode = $this->validatedResultCode($resultCode);
        $errorSummary = $this->safeErrorSummary($errorSummary);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowSql = $now->format('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $job = $this->lockedJob($jobId, $workerId);
            $attemptNo = (int) $job['attempts'];
            $maxAttempts = (int) $job['max_attempts'];
            $terminal = !$retryable || $attemptNo >= $maxAttempts;
            $state = $terminal ? 'failed' : 'retry';
            $nextAttemptAt = $terminal
                ? $nowSql
                : $this->retryPolicy->nextAttemptAt($attemptNo, $now)->format('Y-m-d H:i:s');

            $this->recordAttempt($jobId, $attemptNo, $state, $resultCode, $errorSummary, $nowSql);
            $this->db->execute(
                'UPDATE notification_delivery_jobs
                 SET state = :state,
                     next_attempt_at = :next_attempt_at,
                     last_result_code = :result_code,
                     locked_at = NULL,
                     locked_by = NULL,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    ':state' => $state,
                    ':next_attempt_at' => $nextAttemptAt,
                    ':result_code' => $resultCode,
                    ':updated_at' => $nowSql,
                    ':id' => $jobId,
                ]
            );
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    /**
     * @param array<int,int> $notificationIdsByUser
     * @return array<int,int>
     */
    private function normalizeNotificationMap(array $notificationIdsByUser): array
    {
        $normalized = [];
        foreach ($notificationIdsByUser as $userId => $notificationId) {
            $userId = (int) $userId;
            $notificationId = (int) $notificationId;
            if ($userId > 0 && $notificationId > 0) {
                $normalized[$userId] = $notificationId;
            }
        }
        return $normalized;
    }

    /**
     * @param list<int> $userIds
     * @return array<int,list<string>>
     */
    private function subscribedChannels(array $userIds, string $category): array
    {
        [$placeholders, $params] = $this->placeholders($userIds, 'subscription_user');
        $params[':category'] = $category;
        $rows = $this->db->fetchAll(
            'SELECT user_id, email_enabled, browser_enabled, telegram_enabled, webhook_enabled, sms_enabled
             FROM notification_subscriptions
             WHERE user_id IN (' . implode(', ', $placeholders) . ')
               AND category = :category
               AND enabled = 1',
            $params
        );

        $channels = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            foreach (self::SUBSCRIPTION_FLAGS as $channel => $column) {
                if ((int) ($row[$column] ?? 0) === 1) {
                    $channels[$userId][] = $channel;
                }
            }
        }

        return $channels;
    }

    /**
     * Индекс 0 содержит глобальные конфигурации, остальные индексы — пользовательские.
     *
     * @param array<int,list<string>> $channelsByUser
     * @return array<int,array<string,int>>
     */
    private function effectiveConfigs(array $channelsByUser): array
    {
        $userIds = array_keys($channelsByUser);
        $channelNames = [];
        foreach ($channelsByUser as $channels) {
            foreach ($channels as $channel) {
                $channelNames[$channel] = $channel;
            }
        }
        if ($userIds === [] || $channelNames === []) {
            return [];
        }

        [$userPlaceholders, $userParams] = $this->placeholders($userIds, 'config_user');
        [$channelPlaceholders, $channelParams] = $this->stringPlaceholders(array_values($channelNames), 'config_channel');
        $rows = $this->db->fetchAll(
            'SELECT id, channel, owner_user_id
             FROM notification_channel_configs
             WHERE enabled = 1
               AND channel IN (' . implode(', ', $channelPlaceholders) . ')
               AND (owner_user_id IS NULL OR owner_user_id IN (' . implode(', ', $userPlaceholders) . '))
             ORDER BY channel ASC, owner_user_id IS NULL ASC, id ASC',
            $userParams + $channelParams
        );

        $configs = [];
        foreach ($rows as $row) {
            $configId = (int) ($row['id'] ?? 0);
            $channel = (string) ($row['channel'] ?? '');
            $owner = $row['owner_user_id'] === null ? 0 : (int) $row['owner_user_id'];
            if ($configId <= 0 || !isset(self::SUBSCRIPTION_FLAGS[$channel])) {
                continue;
            }
            $configs[$owner][$channel] ??= $configId;
        }

        return $configs;
    }

    /** @return array<string,mixed> */
    private function lockedJob(int $jobId, string $workerId): array
    {
        if ($jobId <= 0) {
            throw new InvalidArgumentException('Идентификатор задания доставки должен быть положительным');
        }

        $job = $this->db->fetchOne(
            'SELECT id, attempts, max_attempts
             FROM notification_delivery_jobs
             WHERE id = :id
               AND state = \'processing\'
               AND locked_by = :locked_by
             FOR UPDATE',
            [':id' => $jobId, ':locked_by' => $workerId]
        );
        if ($job === null) {
            throw new RuntimeException('Задание доставки не принадлежит указанному worker или уже завершено');
        }

        return $job;
    }

    private function recordInterruptedAttempt(int $jobId, int $attemptNo, string $attemptedAt): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO notification_delivery_attempts (
                job_id, attempt_no, result_state, result_code, error_summary, attempted_at
             ) VALUES (
                :job_id, :attempt_no, \'retry\', \'worker_interrupted\', :error_summary, :attempted_at
             )',
            [
                ':job_id' => $jobId,
                ':attempt_no' => $attemptNo,
                ':error_summary' => 'Предыдущая попытка не завершилась до истечения блокировки worker',
                ':attempted_at' => $attemptedAt,
            ]
        );
    }

    private function recordAttempt(
        int $jobId,
        int $attemptNo,
        string $state,
        string $resultCode,
        ?string $errorSummary,
        string $attemptedAt
    ): void {
        $this->db->execute(
            'INSERT INTO notification_delivery_attempts (
                job_id, attempt_no, result_state, result_code, error_summary, attempted_at
             ) VALUES (
                :job_id, :attempt_no, :result_state, :result_code, :error_summary, :attempted_at
             )',
            [
                ':job_id' => $jobId,
                ':attempt_no' => $attemptNo,
                ':result_state' => $state,
                ':result_code' => $resultCode,
                ':error_summary' => $errorSummary,
                ':attempted_at' => $attemptedAt,
            ]
        );
    }

    /**
     * @param list<int> $values
     * @return array{0:list<string>,1:array<string,int>}
     */
    private function placeholders(array $values, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach ($values as $index => $value) {
            $name = ':' . $prefix . '_' . $index;
            $placeholders[] = $name;
            $params[$name] = (int) $value;
        }
        return [$placeholders, $params];
    }

    /**
     * @param list<string> $values
     * @return array{0:list<string>,1:array<string,string>}
     */
    private function stringPlaceholders(array $values, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach ($values as $index => $value) {
            $name = ':' . $prefix . '_' . $index;
            $placeholders[] = $name;
            $params[$name] = $value;
        }
        return [$placeholders, $params];
    }

    private function validatedWorkerId(string $workerId): string
    {
        $workerId = trim($workerId);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{2,79}$/', $workerId) !== 1) {
            throw new InvalidArgumentException('Идентификатор worker имеет недопустимый формат');
        }
        return $workerId;
    }

    private function validatedResultCode(string $resultCode): string
    {
        $resultCode = trim($resultCode);
        if (preg_match('/^[a-z0-9_.-]{2,80}$/', $resultCode) !== 1) {
            throw new InvalidArgumentException('Код результата доставки имеет недопустимый формат');
        }
        return $resultCode;
    }

    private function safeErrorSummary(?string $summary): ?string
    {
        if ($summary === null) {
            return null;
        }
        $summary = trim($summary);
        if ($summary === '') {
            return null;
        }
        return function_exists('mb_substr') ? mb_substr($summary, 0, 500) : substr($summary, 0, 500);
    }

    private function nowSql(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
