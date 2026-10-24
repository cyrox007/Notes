<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/core/Config.php';

use Core\CliRuntime;
use Core\DatabaseManager;
use App\Services\Notifications\NotificationWorkerScheduleHealth;

$options = getopt('', ['json', 'help']);
if (isset($options['help'])) {
    echo "Использование: php bin/notifications_diagnose.php [--json]\n";
    echo "Проверяет состояние уведомлений и очереди без отправки сообщений и раскрытия секретов.\n";
    exit(0);
}

$json = isset($options['json']);

try {
    $db = DatabaseManager::getInstance();

    $events = (int) $db->fetchValue('SELECT COUNT(*) FROM notification_events');
    $notifications = (int) $db->fetchValue('SELECT COUNT(*) FROM notifications');
    $configs = $db->fetchAll(
        "SELECT enabled, COUNT(*) AS total
         FROM notification_channel_configs
         WHERE channel = 'email'
         GROUP BY enabled"
    );
    $subscriptions = $db->fetchOne(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN enabled = 1 AND email_enabled = 1 THEN 1 ELSE 0 END), 0) AS active
         FROM notification_subscriptions"
    );
    $jobs = $db->fetchAll(
        "SELECT state, COUNT(*) AS total
         FROM notification_delivery_jobs
         WHERE channel = 'email'
         GROUP BY state"
    );
    $lastAttempts = $db->fetchAll(
        "SELECT result_code, COUNT(*) AS total
         FROM notification_delivery_attempts
         WHERE attempted_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR
           AND job_id IN (
               SELECT id FROM notification_delivery_jobs WHERE channel = 'email'
           )
         GROUP BY result_code
         ORDER BY total DESC
         LIMIT 15"
    );
    // Агрегаты всех каналов: не читаем адреса получателей, секреты
    // и содержимое пользовательских уведомлений.
    $channelConfigs = $db->fetchAll(
        'SELECT channel, enabled, COUNT(*) AS total
         FROM notification_channel_configs
         GROUP BY channel, enabled'
    );
    $channelSubscriptions = $db->fetchOne(
        'SELECT
            COALESCE(SUM(CASE WHEN enabled = 1 AND email_enabled = 1 THEN 1 ELSE 0 END), 0) AS email,
            COALESCE(SUM(CASE WHEN enabled = 1 AND browser_enabled = 1 THEN 1 ELSE 0 END), 0) AS browser,
            COALESCE(SUM(CASE WHEN enabled = 1 AND telegram_enabled = 1 THEN 1 ELSE 0 END), 0) AS telegram,
            COALESCE(SUM(CASE WHEN enabled = 1 AND max_enabled = 1 THEN 1 ELSE 0 END), 0) AS max,
            COALESCE(SUM(CASE WHEN enabled = 1 AND webhook_enabled = 1 THEN 1 ELSE 0 END), 0) AS webhook,
            COALESCE(SUM(CASE WHEN enabled = 1 AND sms_enabled = 1 THEN 1 ELSE 0 END), 0) AS sms
         FROM notification_subscriptions'
    );
    $channelJobs = $db->fetchAll(
        'SELECT channel, state, COUNT(*) AS total
         FROM notification_delivery_jobs
         GROUP BY channel, state'
    );
    $channelAttemptCounts = $db->fetchAll(
        'SELECT j.channel, a.result_state, COUNT(*) AS total
         FROM notification_delivery_attempts a
         INNER JOIN notification_delivery_jobs j ON j.id = a.job_id
         WHERE a.attempted_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR
         GROUP BY j.channel, a.result_state'
    );
    $lastFallback = $db->fetchValue(
        "SELECT last_http_run_at FROM notification_runtime_state
         WHERE state_key = :state_key LIMIT 1",
        [':state_key' => 'delivery_http_fallback']
    );
    $workerHealth = NotificationWorkerScheduleHealth::evaluate(
        NotificationWorkerScheduleHealth::readLastSuccess($db)
    );
    $configurationCounts = ['active' => 0, 'disabled' => 0];
    foreach ($configs as $config) {
        $key = (int) $config['enabled'] === 1 ? 'active' : 'disabled';
        $configurationCounts[$key] += (int) $config['total'];
    }

    $stateCounts = [];
    foreach ($jobs as $job) {
        $stateCounts[(string) $job['state']] = (int) $job['total'];
    }

    $attemptCodes = [];
    foreach ($lastAttempts as $attempt) {
        $attemptCodes[] = [
            'code' => (string) ($attempt['result_code'] ?? 'unknown'),
            'count' => (int) $attempt['total'],
        ];
    }

    $channels = [];
    foreach (['email', 'browser', 'telegram', 'max', 'webhook', 'sms'] as $name) {
        $channels[$name] = [
            'configurations' => ['active' => 0, 'disabled' => 0],
            'active_subscriptions' => (int) ($channelSubscriptions[$name] ?? 0),
            'jobs_by_state' => [],
            'attempts_last_24h_by_state' => [],
            'actual_delivery_verified' => false,
        ];
    }
    foreach ($channelConfigs as $row) {
        $name = (string) $row['channel'];
        if (!isset($channels[$name])) {
            continue;
        }
        $key = (int) $row['enabled'] === 1 ? 'active' : 'disabled';
        $channels[$name]['configurations'][$key] += (int) $row['total'];
    }
    foreach ($channelJobs as $row) {
        $name = (string) $row['channel'];
        if (isset($channels[$name])) {
            $channels[$name]['jobs_by_state'][(string) $row['state']] = (int) $row['total'];
        }
    }
    foreach ($channelAttemptCounts as $row) {
        $name = (string) $row['channel'];
        if (isset($channels[$name])) {
            $channels[$name]['attempts_last_24h_by_state'][(string) $row['result_state']] = (int) $row['total'];
        }
    }

    $result = [
        'status' => 'ok',
        'mode' => 'read_only',
        'events_total' => $events,
        'notifications_total' => $notifications,
        'channels' => $channels,
        'smtp_configurations' => $configurationCounts,
        'subscriptions_total' => (int) ($subscriptions['total'] ?? 0),
        'smtp_subscriptions_active' => (int) ($subscriptions['active'] ?? 0),
        'smtp_jobs_by_state' => $stateCounts,
        'smtp_attempt_codes_last_24h' => $attemptCodes,
        'last_http_fallback_utc' => is_string($lastFallback) ? $lastFallback : null,
        'cli_worker_last_success_utc' => $workerHealth['last_success_utc'],
        'cli_worker_state' => $workerHealth['state'],
        'scheduler_running' => 'not_verified',
        'smtp_delivery_received' => 'not_verified',
    ];

    if ($json) {
        CliRuntime::writeJson($result, true);
    } else {
        echo "Диагностика внешних уведомлений (только чтение)\n";
        echo 'Событий: ' . $events . '; уведомлений: ' . $notifications . PHP_EOL;
        echo 'Все каналы (агрегированные показатели): ' . json_encode($channels, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        echo 'SMTP-конфигурации: ' . json_encode($configurationCounts, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        echo 'Активные подписки SMTP: ' . $result['smtp_subscriptions_active'] . PHP_EOL;
        echo 'Очередь SMTP: ' . json_encode($stateCounts, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        echo 'Коды попыток за 24 часа: ' . json_encode($attemptCodes, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        echo 'Последний успешный проход отдельного обработчика (UTC): '
            . ($result['cli_worker_last_success_utc'] ?? 'не зафиксирован') . PHP_EOL;
        echo 'Состояние отдельного обработчика: ' . $result['cli_worker_state'] . PHP_EOL;
        echo 'Последний резервный проход (UTC): ' . ($result['last_http_fallback_utc'] ?? 'не зафиксирован') . PHP_EOL;
        echo "Работа планировщика и фактическое получение письма этой командой не проверяются.\n";
    }

    DatabaseManager::resetInstance();
} catch (Throwable $exception) {
    DatabaseManager::resetInstance();
    CliRuntime::fail($exception, $json, 'notification_diagnostics_failed');
}
