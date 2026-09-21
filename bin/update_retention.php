<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/core/Environment.php';
if (is_file($root . '/.env')) {
    \Core\Environment::load($root . '/.env');
}
require_once $root . '/core/UpdateArtifactCleaner.php';
require_once $root . '/core/SecurityEventLog.php';
require_once $root . '/app/services/MaintenanceModeService.php';

use App\Services\MaintenanceModeService;
use Core\SecurityEventLog;
use Core\UpdateArtifactCleaner;
use Throwable;

$options = getopt('', [
    'state-root:',
    'backup-root:',
    'candidate-root:',
    'older-than-days:',
    'keep:',
    'apply',
    'yes',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Workspace Organizer updater retention cleanup\n\n";
    echo "Preview old terminal recovery artifacts (default: older than 30 days, keep newest 2):\n";
    echo "  php bin/update_retention.php [--older-than-days=30] [--keep=2] [--json]\n\n";
    echo "Apply cleanup:\n";
    echo "  php bin/update_retention.php --apply --yes [--older-than-days=30] [--keep=2] [--json]\n\n";
    echo "Only committed/rollback_verified rollback backups and release candidates are eligible.\n";
    echo "Transaction journals, active/incomplete recovery state and staged packages are preserved.\n";
    exit(0);
}

$json = isset($options['json']);

/** @return never */
function updateRetentionFail(string $message, string $code = 'retention_failed', int $exitCode = 1): never
{
    global $json;
    if ($json) {
        echo json_encode([
            'status' => 'fail',
            'code' => $code,
            'message' => $message,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, "[FAIL] {$message}\n");
    }
    exit($exitCode);
}

function updateRetentionRoot(array $options, string $option, string $env, string $suffix): string
{
    $explicit = trim((string) ($options[$option] ?? ''));
    if ($explicit !== '') {
        return rtrim($explicit, '/\\');
    }
    $configured = getenv($env);
    if (is_string($configured) && trim($configured) !== '') {
        return rtrim(trim($configured), '/\\');
    }
    $private = getenv('PRIVATE_STORAGE_PATH');
    if (is_string($private) && trim($private) !== '') {
        return rtrim(trim($private), '/\\') . DIRECTORY_SEPARATOR . $suffix;
    }
    updateRetentionFail("{$env} is not configured and PRIVATE_STORAGE_PATH is unavailable", 'path_not_configured', 2);
}

function updateRetentionInt(array $options, string $name, int $default): int
{
    if (!array_key_exists($name, $options)) {
        return $default;
    }
    $raw = trim((string) $options[$name]);
    if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
        updateRetentionFail("--{$name} must be a non-negative integer", 'invalid_option', 2);
    }
    return (int) $raw;
}

$apply = isset($options['apply']);
if ($apply && !isset($options['yes'])) {
    updateRetentionFail(
        'Refusing updater artifact deletion without explicit --apply --yes confirmation.',
        'confirmation_required',
        2
    );
}
if (!$apply && isset($options['yes'])) {
    updateRetentionFail('--yes is valid only together with --apply', 'invalid_option', 2);
}

$olderThanDays = updateRetentionInt($options, 'older-than-days', 30);
$keep = updateRetentionInt($options, 'keep', 2);

$stateRoot = updateRetentionRoot($options, 'state-root', 'UPDATE_STATE_PATH', 'updates');
$backupRoot = updateRetentionRoot($options, 'backup-root', 'UPDATE_BACKUP_PATH', 'update-backups');
$candidateRoot = updateRetentionRoot($options, 'candidate-root', 'UPDATE_RELEASE_PATH', 'update-releases');

try {
    if ($apply) {
        $maintenance = new MaintenanceModeService($stateRoot, $root);
        $maintenanceState = $maintenance->state();
        if (($maintenanceState['active'] ?? false) === true) {
            updateRetentionFail(
                'Updater retention refuses to delete artifacts while maintenance is active.',
                'maintenance_active',
                75
            );
        }
    }

    $result = (new UpdateArtifactCleaner(
        $stateRoot,
        $backupRoot,
        $candidateRoot,
        $root
    ))->run($olderThanDays, $keep, $apply);

    if ($apply) {
        SecurityEventLog::emit(
            'update.retention_cleanup',
            'info',
            'updater',
            'cli',
            null,
            [
                'older_than_days' => $olderThanDays,
                'keep' => $keep,
                'eligible_transactions' => (int) ($result['eligible_transactions'] ?? 0),
                'deleted_directories' => (int) ($result['deleted_directories'] ?? 0),
                'deleted_bytes' => (int) ($result['deleted_bytes'] ?? 0),
            ]
        );
    }

    if ($json) {
        echo json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        exit(0);
    }

    echo $apply ? "[OK] Updater retention cleanup applied\n" : "[OK] Updater retention preview\n";
    echo 'Terminal transactions: ' . (int) ($result['terminal_transactions'] ?? 0) . PHP_EOL;
    echo 'Eligible transactions: ' . (int) ($result['eligible_transactions'] ?? 0) . PHP_EOL;
    if ($apply) {
        echo 'Deleted directories:   ' . (int) ($result['deleted_directories'] ?? 0) . PHP_EOL;
        echo 'Deleted bytes:         ' . (int) ($result['deleted_bytes'] ?? 0) . PHP_EOL;
    } else {
        echo "No files were deleted. Use --apply --yes after reviewing the preview.\n";
    }
} catch (Throwable $e) {
    updateRetentionFail($e->getMessage());
}
