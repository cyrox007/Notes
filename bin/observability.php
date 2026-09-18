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
require_once $root . '/core/OperationalTelemetry.php';

use Core\OperationalTelemetry;

$json = in_array('--json', $argv, true);
$failOnAlert = in_array('--fail-on-alert', $argv, true);

try {
    $telemetry = new OperationalTelemetry();
    $result = $telemetry->evaluateAlerts();

    if ($json) {
        echo json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } else {
        $window = $result['window'];
        echo 'Observability: ' . strtoupper((string) $result['status']) . PHP_EOL;
        echo 'Storage: ' . (string) $result['root'] . PHP_EOL;
        echo 'Window: ' . gmdate('c', (int) $window['window_start'])
            . ' +' . (int) $window['window_seconds'] . 's' . PHP_EOL;

        $counters = is_array($window['counters'] ?? null) ? $window['counters'] : [];
        if ($counters === []) {
            echo "Events: none in current window\n";
        } else {
            ksort($counters, SORT_STRING);
            foreach ($counters as $event => $count) {
                echo sprintf("  %-40s %d\n", (string) $event, (int) $count);
            }
        }

        $alerts = is_array($result['alerts'] ?? null) ? $result['alerts'] : [];
        foreach ($alerts as $alert) {
            if (!is_array($alert)) {
                continue;
            }
            echo sprintf(
                "[ALERT] %s = %d (threshold %d)\n",
                (string) ($alert['metric'] ?? 'unknown'),
                (int) ($alert['value'] ?? 0),
                (int) ($alert['threshold'] ?? 0)
            );
        }
    }

    exit($failOnAlert && ($result['status'] ?? '') === 'alert' ? 3 : 0);
} catch (Throwable $e) {
    if ($json) {
        echo json_encode([
            'status' => 'fail',
            'error' => 'observability_unavailable',
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, '[FAIL] Observability unavailable: ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
