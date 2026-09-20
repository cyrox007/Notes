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
require_once $root . '/core/RuntimeAutoloader.php';
\Core\RuntimeAutoloader::register($root);
require_once $root . '/core/config.php';

use Core\UserActionLog;

$options = getopt('', ['days:', 'limit:', 'apply', 'yes', 'json', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/audit_log.php [--days=N] [--limit=N] [--json]\n";
    echo "       php bin/audit_log.php --apply --yes [same options]\n";
    echo "Default mode is preview. --apply --yes permanently deletes expired audit rows.\n";
    exit(0);
}

$defaultDaysRaw = trim((string) (getenv('AUDIT_LOG_RETENTION_DAYS') ?: '180'));
$defaultDays = ctype_digit($defaultDaysRaw) ? (int) $defaultDaysRaw : 180;
$days = isset($options['days']) ? (int) $options['days'] : $defaultDays;
$limit = isset($options['limit']) ? (int) $options['limit'] : 1000;
$apply = isset($options['apply']);
$confirmed = isset($options['yes']);
$json = isset($options['json']);

try {
    if ($apply && !$confirmed) {
        throw new RuntimeException('Audit purge requires explicit --yes confirmation');
    }
    if (!$apply && $confirmed) {
        throw new RuntimeException('--yes is valid only together with --apply');
    }

    $log = new UserActionLog();
    $eligible = $log->countOlderThan($days);
    $deleted = 0;
    if ($apply) {
        $deleted = $log->purgeOlderThan($days, $limit);
    }

    $result = [
        'status' => 'ok',
        'mode' => $apply ? 'apply' : 'preview',
        'retention_days' => $days,
        'eligible' => $eligible,
        'deleted' => $deleted,
        'limit' => max(1, min(5000, $limit)),
    ];

    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    } elseif ($apply) {
        echo "Audit retention purge applied\n";
        echo "Retention: {$days} day(s)\n";
        echo "Eligible before purge: {$eligible}\n";
        echo "Deleted this batch: {$deleted}\n";
    } else {
        echo "Audit retention preview\n";
        echo "Retention: {$days} day(s)\n";
        echo "Eligible rows: {$eligible}\n";
        echo "No data changed. Re-run with --apply --yes to delete up to {$result['limit']} rows.\n";
    }
    exit(0);
} catch (Throwable $e) {
    if ($json) {
        echo json_encode([
            'status' => 'fail',
            'error' => 'audit_retention_failed',
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
