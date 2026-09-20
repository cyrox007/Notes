<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/core/Environment.php';
if (is_file($root . '/.env')) {
    \Core\Environment::load($root . '/.env');
}
require_once $root . '/core/SecurityEventLog.php';

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
    echo "Usage: php bin/observability.php [--window=SECONDS] [--json]\n";
    echo "       [--critical-threshold=N] [--auth-failure-threshold=N] [--rate-limit-threshold=N]\n";
    echo "Summarizes structured security events and exits 3 when alert thresholds are crossed.\n";
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
        echo json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } else {
        echo 'Security event window: ' . $summary['window_seconds'] . "s\n";
        echo 'Events: ' . $summary['events'] . PHP_EOL;
        echo 'Severity: info=' . $summary['severity']['info']
            . ' warning=' . $summary['severity']['warning']
            . ' critical=' . $summary['severity']['critical'] . PHP_EOL;
        foreach ($summary['counts'] as $event => $count) {
            echo sprintf("  %-36s %d\n", $event, $count);
        }
        if ($summary['alerts'] === []) {
            echo "Alerts: none\n";
        } else {
            echo "Alerts:\n";
            foreach ($summary['alerts'] as $alert) {
                echo '  [ALERT] ' . $alert['code']
                    . ' count=' . $alert['count']
                    . ' threshold=' . $alert['threshold'] . PHP_EOL;
            }
        }
        echo 'Log: ' . $summary['log_path'] . PHP_EOL;
    }

    exit($summary['alerts'] === [] ? 0 : 3);
} catch (Throwable $e) {
    if ($json) {
        echo json_encode([
            'status' => 'fail',
            'error' => 'observability_unavailable',
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
