<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/SecurityEventLog.php';

use Core\SecurityEventLog;

function observabilityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] security observability: {$message}\n");
        exit(1);
    }
}

function observabilityRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $candidate = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($candidate) && !is_link($candidate)) {
            observabilityRemoveTree($candidate);
        } else {
            @unlink($candidate);
        }
    }
    @rmdir($path);
}

$temp = sys_get_temp_dir() . '/notes-security-observability-' . bin2hex(random_bytes(6));
observabilityAssert(mkdir($temp, 0700, true), 'cannot create external test directory');
$logPath = $temp . '/security-events.jsonl';

try {
    $log = new SecurityEventLog($logPath, $root);
    $now = time();

    $log->record(
        'auth.login_success',
        'info',
        'auth',
        'user',
        7,
        [
            'client_ip' => '203.0.113.5',
            'license_token' => 'LICENSE-TOKEN-MUST-NOT-LEAK',
            'password' => 'PASSWORD-MUST-NOT-LEAK',
            'nested' => ['cookie' => 'COOKIE-MUST-NOT-LEAK', 'safe' => 'visible'],
        ]
    );
    $log->record('auth.login_failed', 'warning', 'auth', 'anonymous', null, ['client_ip' => '203.0.113.6']);
    $log->record('auth.login_blocked', 'warning', 'auth', 'user', 8);
    $log->record('auth.rate_limited', 'warning', 'auth_rate_limit', 'anonymous', null, ['retry_after' => 30]);
    $log->record('update.apply_failed', 'critical', 'updater', 'cli', null, ['error_code' => 'fixture']);

    observabilityAssert(is_file($logPath), 'JSONL log was not created');
    $raw = (string) file_get_contents($logPath);
    foreach ([
        'LICENSE-TOKEN-MUST-NOT-LEAK',
        'PASSWORD-MUST-NOT-LEAK',
        'COOKIE-MUST-NOT-LEAK',
    ] as $secret) {
        observabilityAssert(!str_contains($raw, $secret), 'sensitive context leaked into JSONL');
    }
    observabilityAssert(substr_count($raw, '[redacted]') >= 3, 'sensitive values were not redacted');

    $lines = array_values(array_filter(explode("\n", trim($raw))));
    observabilityAssert(count($lines) === 5, 'unexpected event line count');
    foreach ($lines as $line) {
        $event = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
        observabilityAssert(($event['schema'] ?? null) === SecurityEventLog::SCHEMA, 'event schema drifted');
        observabilityAssert(is_int($event['ts'] ?? null), 'event timestamp missing');
        observabilityAssert(is_string($event['event'] ?? null), 'event name missing');
        observabilityAssert(is_array($event['context'] ?? null), 'event context missing');
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        $permissions = fileperms($logPath);
        observabilityAssert(is_int($permissions) && (($permissions & 0777) === 0600), 'event log mode must be 0600');
    }

    $summary = $log->summarize(
        3600,
        ['critical' => 1, 'auth_failures' => 2, 'rate_limited' => 1],
        $now + 1
    );
    observabilityAssert(($summary['status'] ?? '') === 'alert', 'threshold crossings did not produce alert state');
    observabilityAssert((int) ($summary['events'] ?? 0) === 5, 'summary event count is incorrect');
    observabilityAssert((int) ($summary['severity']['critical'] ?? 0) === 1, 'critical count is incorrect');

    $alertCodes = array_map(
        static fn (array $alert): string => (string) ($alert['code'] ?? ''),
        $summary['alerts'] ?? []
    );
    foreach ([
        'critical_security_events',
        'authentication_failures',
        'authentication_rate_limited',
    ] as $code) {
        observabilityAssert(in_array($code, $alertCodes, true), "missing alert code {$code}");
    }

    $insideRejected = false;
    try {
        new SecurityEventLog($root . '/cache/security-events.jsonl', $root);
    } catch (Throwable $e) {
        $insideRejected = str_contains($e->getMessage(), 'outside the application tree');
    }
    observabilityAssert($insideRejected, 'logger accepted storage inside the application tree');

    $sources = [
        'app/controllers/AuthController.php' => ['auth.login_failed', 'auth.login_success', 'auth.logout'],
        'app/middlewares/AuthRateLimit.php' => ['auth.rate_limited', 'auth.rate_limiter_failed'],
        'app/services/LicenseService.php' => ['license.activated', 'license.cleared'],
        'core/ModuleLifecycleStore.php' => ['module.lifecycle_changed'],
        'bin/update_apply.php' => ['update.apply_succeeded', 'update.apply_failed'],
        'bin/healthcheck.php' => ['security_event_log'],
        'bin/observability.php' => ['OBSERVABILITY_AUTH_FAILURE_ALERT'],
    ];
    foreach ($sources as $relative => $markers) {
        $source = (string) file_get_contents($root . '/' . $relative);
        foreach ($markers as $marker) {
            observabilityAssert(str_contains($source, $marker), "{$relative} missing marker {$marker}");
        }
    }

    $docs = (string) file_get_contents($root . '/docs/OPERATIONS.md');
    observabilityAssert(str_contains($docs, '## Security observability'), 'operations runbook lacks observability section');

    fwrite(STDOUT, "[OK] structured security events, redaction, metrics and alert thresholds\n");
} finally {
    observabilityRemoveTree($temp);
}
