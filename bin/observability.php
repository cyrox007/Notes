<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
require_once $root . '/core/SecurityEventLog.php';

use Core\CliRuntime;
use Core\SecurityEventLog;

$options = getopt('', [
    'window:',
    'critical-threshold:',
    'auth-failure-threshold:',
    'rate-limit-threshold:',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Использование: php bin/observability.php [--window=СЕКУНДЫ] [--json]\n";
    echo "              [--critical-threshold=N] [--auth-failure-threshold=N] [--rate-limit-threshold=N]\n";
    echo "Команда сводит структурированные события безопасности и завершает работу с кодом 3 при превышении порогов.\n";
    exit(0);
}

$window = isset($options['window'])
    ? (int) $options['window']
    : max(60, (int) (getenv('OBSERVABILITY_WINDOW_SECONDS') ?: 900));
$thresholds = [
    'critical' => isset($options['critical-threshold'])
        ? (int) $options['critical-threshold']
        : max(1, (int) (getenv('OBSERVABILITY_CRITICAL_ALERT') ?: 1)),
    'auth_failures' => isset($options['auth-failure-threshold'])
        ? (int) $options['auth-failure-threshold']
        : max(1, (int) (getenv('OBSERVABILITY_AUTH_FAILURE_ALERT') ?: 10)),
    'rate_limited' => isset($options['rate-limit-threshold'])
        ? (int) $options['rate-limit-threshold']
        : max(1, (int) (getenv('OBSERVABILITY_RATE_LIMIT_ALERT') ?: 3)),
];
$json = isset($options['json']);

try {
    $summary = (new SecurityEventLog())->summarize($window, $thresholds);
    if ($json) {
        CliRuntime::writeJson($summary, true);
        exit($summary['alerts'] === [] ? 0 : 3);
    }

    echo 'Окно событий безопасности: ' . $summary['window_seconds'] . " с\n";
    echo 'Событий: ' . $summary['events'] . PHP_EOL;
    echo 'Уровни: info=' . $summary['severity']['info']
        . ' warning=' . $summary['severity']['warning']
        . ' critical=' . $summary['severity']['critical'] . PHP_EOL;
    foreach ($summary['counts'] as $event => $count) {
        echo sprintf("  %-36s %d\n", $event, $count);
    }

    if ($summary['alerts'] === []) {
        echo "Предупреждения: нет\n";
    } else {
        echo "Предупреждения:\n";
        foreach ($summary['alerts'] as $alert) {
            echo '  [ALERT] ' . $alert['code']
                . ' count=' . $alert['count']
                . ' threshold=' . $alert['threshold'] . PHP_EOL;
        }
    }
    echo 'Журнал: ' . $summary['log_path'] . PHP_EOL;

    exit($summary['alerts'] === [] ? 0 : 3);
} catch (Throwable $e) {
    CliRuntime::fail($e, $json, 'observability_unavailable');
}
