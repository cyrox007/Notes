<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once dirname(__DIR__, 3) . '/core/DatabaseManager.php';
require_once __DIR__ . '/DatabaseNotificationDeliveryQueue.php';
require_once __DIR__ . '/DatabaseNotificationEmailRecipientResolver.php';
require_once __DIR__ . '/EnvironmentNotificationSecretResolver.php';
require_once __DIR__ . '/NotificationDeliveryWorker.php';
require_once __DIR__ . '/SmtpNotificationChannel.php';
require_once __DIR__ . '/SocketSmtpTransport.php';

use Core\DatabaseManager;
use DateTimeImmutable;
use DateTimeZone;

final class NotificationDeliveryRuntime
{
    public const HTTP_LIMIT = 3;
    public const HTTP_INTERVAL_SECONDS = 60;

    private const HTTP_LOCK_NAME = 'workspace.notifications.delivery.http_fallback';
    private const HTTP_STATE_KEY = 'delivery_http_fallback';

    private DatabaseManager $db;
    private ?NotificationDeliveryWorker $worker;

    public function __construct(?DatabaseManager $db = null, ?NotificationDeliveryWorker $worker = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance();
        $this->worker = $worker;
    }

    /** @return array{claimed:int,sent:int,retry:int,failed:int} */
    public function run(string $workerId, int $limit = 20): array
    {
        return $this->worker()->run($workerId, $limit);
    }

    /**
     * Резервный проход для виртуального хостинга без постоянного worker.
     *
     * @return array{locked:bool,throttled:bool,claimed:int,sent:int,retry:int,failed:int}
     */
    public function runHttpFallback(?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        if (!$this->httpRunDue($now)) {
            return $this->emptyHttpResult(false, true);
        }
        if (!$this->acquireHttpLock()) {
            return $this->emptyHttpResult(true, false);
        }

        try {
            if (!$this->httpRunDue($now)) {
                return $this->emptyHttpResult(false, true);
            }

            $this->rememberHttpRun($now);
            $stats = $this->run($this->httpWorkerId(), self::HTTP_LIMIT);
            return [
                'locked' => false,
                'throttled' => false,
                ...$stats,
            ];
        } finally {
            $this->releaseHttpLock();
        }
    }

    private function worker(): NotificationDeliveryWorker
    {
        if ($this->worker instanceof NotificationDeliveryWorker) {
            return $this->worker;
        }

        $siteUrl = getenv('SITEURL');
        $email = new SmtpNotificationChannel(
            new DatabaseNotificationEmailRecipientResolver($this->db),
            new EnvironmentNotificationSecretResolver(),
            new SocketSmtpTransport(),
            $siteUrl === false ? '' : $siteUrl
        );
        $this->worker = new NotificationDeliveryWorker(
            new DatabaseNotificationDeliveryQueue($this->db),
            [$email]
        );
        return $this->worker;
    }

    private function httpRunDue(DateTimeImmutable $now): bool
    {
        $row = $this->db->fetchOne(
            'SELECT last_http_run_at
             FROM notification_runtime_state
             WHERE state_key = :state_key
             LIMIT 1',
            [':state_key' => self::HTTP_STATE_KEY]
        );
        $lastRun = trim((string) ($row['last_http_run_at'] ?? ''));
        if ($lastRun === '') {
            return true;
        }

        $last = new DateTimeImmutable($lastRun, new DateTimeZone('UTC'));
        return $last <= $now->modify('-' . self::HTTP_INTERVAL_SECONDS . ' seconds');
    }

    private function rememberHttpRun(DateTimeImmutable $now): void
    {
        $timestamp = $now->format('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO notification_runtime_state (state_key, last_http_run_at, updated_at)
             VALUES (:state_key, :last_http_run_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                last_http_run_at = VALUES(last_http_run_at),
                updated_at = VALUES(updated_at)',
            [
                ':state_key' => self::HTTP_STATE_KEY,
                ':last_http_run_at' => $timestamp,
                ':updated_at' => $timestamp,
            ]
        );
    }

    private function acquireHttpLock(): bool
    {
        return (int) $this->db->fetchValue(
            'SELECT GET_LOCK(:lock_name, 0)',
            [':lock_name' => self::HTTP_LOCK_NAME]
        ) === 1;
    }

    private function releaseHttpLock(): void
    {
        $this->db->fetchValue(
            'SELECT RELEASE_LOCK(:lock_name)',
            [':lock_name' => self::HTTP_LOCK_NAME]
        );
    }

    private function httpWorkerId(): string
    {
        $host = (string) (gethostname() ?: 'host');
        $fingerprint = substr(hash('sha256', $host), 0, 12);
        return 'notifications-http-' . $fingerprint . '-' . max(1, (int) getmypid());
    }

    /** @return array{locked:bool,throttled:bool,claimed:int,sent:int,retry:int,failed:int} */
    private function emptyHttpResult(bool $locked, bool $throttled): array
    {
        return [
            'locked' => $locked,
            'throttled' => $throttled,
            'claimed' => 0,
            'sent' => 0,
            'retry' => 0,
            'failed' => 0,
        ];
    }
}
