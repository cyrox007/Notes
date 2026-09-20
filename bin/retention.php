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

use App\Services\RetentionService;

$options = getopt('', [
    'soft-days:',
    'account-days:',
    'limit:',
    'apply',
    'yes',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Usage: php bin/retention.php [--soft-days=N] [--account-days=N] [--limit=N] [--json]\n";
    echo "       php bin/retention.php --apply --yes [same options]\n";
    echo "Default mode is dry-run/preview. Permanent purge is irreversible and requires both --apply and --yes.\n";
    exit(0);
}

$softDays = isset($options['soft-days'])
    ? (int) $options['soft-days']
    : max(1, (int) (getenv('RETENTION_SOFT_DELETE_DAYS') ?: 30));
$accountDays = isset($options['account-days'])
    ? (int) $options['account-days']
    : max(1, (int) (getenv('RETENTION_DEACTIVATED_ACCOUNT_DAYS') ?: 30));
$limit = isset($options['limit']) ? (int) $options['limit'] : 100;
$apply = isset($options['apply']);
$confirmed = isset($options['yes']);
$json = isset($options['json']);

try {
    if ($apply && !$confirmed) {
        throw new RuntimeException('Permanent purge requires explicit --yes confirmation');
    }
    if (!$apply && $confirmed) {
        throw new RuntimeException('--yes is valid only together with --apply');
    }

    $service = new RetentionService();
    $result = $apply
        ? $service->apply($softDays, $accountDays, $limit)
        : $service->preview($softDays, $accountDays, $limit);

    if ($json) {
        echo json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } elseif (!$apply) {
        echo "Retention preview\n";
        echo "Soft-delete retention: {$result['soft_delete_days']} day(s), cutoff {$result['soft_delete_cutoff']} UTC\n";
        echo "Deactivated-account retention: {$result['account_days']} day(s), cutoff {$result['account_cutoff']} UTC\n";
        foreach ($result['soft_delete_candidates'] as $type => $count) {
            echo sprintf("  %-28s %d\n", $type, $count);
        }
        echo 'Accounts eligible: ' . $result['account_candidates']['eligible'] . PHP_EOL;
        echo 'Accounts blocked by safety policy: ' . $result['account_candidates']['blocked'] . PHP_EOL;
        echo "No data changed. Re-run with --apply --yes to purge eligible records.\n";
    } else {
        echo "Retention purge applied\n";
        foreach ($result['purged'] as $type => $count) {
            echo sprintf("  %-28s %d\n", $type, $count);
        }
        echo 'Files deleted: ' . $result['files_deleted'] . PHP_EOL;
        echo 'Files missing: ' . $result['files_missing'] . PHP_EOL;
        echo 'Files blocked: ' . $result['files_blocked'] . PHP_EOL;
        echo 'Files failed: ' . $result['files_failed'] . PHP_EOL;
        echo 'Accounts purged: ' . $result['accounts_purged'] . PHP_EOL;
        echo 'Accounts blocked: ' . $result['accounts_blocked'] . PHP_EOL;
        echo 'Accounts failed: ' . $result['accounts_failed'] . PHP_EOL;
    }

    if ($apply && (
        (int) $result['files_blocked'] > 0
        || (int) $result['files_failed'] > 0
        || (int) $result['accounts_failed'] > 0
    )) {
        exit(3);
    }
    exit(0);
} catch (Throwable $e) {
    if ($json) {
        echo json_encode([
            'status' => 'fail',
            'error' => 'retention_failed',
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
