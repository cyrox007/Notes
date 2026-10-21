<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/core/Config.php';

use Core\CliRuntime;
use Core\DatabaseManager;

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
    $lastFallback = $db->fetchValue(
        "SELECT last_http_run_at FROM notification_runtime_state
         WHERE state_key = :state_key LIMIT 1",
        [':state_key' => 'delivery_http_fallback']
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

    $result = [
        'status' => 'ok',
        'mode' => 'read_only',
        'events_total' => $events,
        'notifications_total' => $notifications,
        'smtp_configurations' => $configurationCounts,
        'subscriptions_total' => (int) ($subscriptions['total'] ?? 0),
        'smtp_subscriptions_active' => (int) ($subscriptions['active'] ?? 0),
        'smtp_jobs_by_state' => $stateCounts,
        'smtp_attempt_codes_last_24h' => $attemptCodes,
        'last_http_fallback_utc' => is_string($lastFallback) ? $lastFallback : null,
        'scheduler_running' => 'not_verified',
        'smtp_delivery_received' => 'not_verified',
    ];

    if ($json) {
        CliRuntime::writeJson($result, true);
    } else {
        echo "Диагностика внешних уведомлений (только чтение)\n";
        echo 'Событий: ' . $events . '; уведомлений: ' . $notifications . PHP_EOL;
        echo 'SMTP-конфигурации: ' . json_encode($configurationCounts, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        echo 'Активные подписки SMTP: ' . $result['smtp_subscriptions_active'] . PHP_EOL;
        echo 'Очередь SMTP: ' . json_encode($stateCounts, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        echo 'Коды попыток за 24 часа: ' . json_encode($attemptCodes, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        echo 'Последний резервный проход (UTC): ' . ($result['last_http_fallback_utc'] ?? 'не зафиксирован') . PHP_EOL;
        echo "Работа планировщика и фактическое получение письма этой командой не проверяются.\n";
    }

    DatabaseManager::resetInstance();
} catch (Throwable $exception) {
    DatabaseManager::resetInstance();
    CliRuntime::fail($exception, $json, 'notification_diagnostics_failed');
}
