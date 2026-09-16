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
require_once $root . '/core/Version.php';
require_once $root . '/core/UpdateTransactionJournal.php';
require_once $root . '/core/UpdateTransactionStateMachine.php';
require_once $root . '/core/UpdateBackupManager.php';
require_once $root . '/core/UpdateLiveApplier.php';
require_once $root . '/core/UpdateApplyOperationLock.php';
require_once $root . '/core/UpdateRollbackCodeRestorer.php';
require_once $root . '/app/services/MaintenanceModeService.php';
require_once $root . '/core/UpdateApplyCommand.php';

use Core\UpdateApplyCommand;
use Core\UpdateApplyException;

$options = getopt('', [
    'transaction:',
    'candidate-dir:',
    'state-root:',
    'backup-root:',
    'apply',
    'recover',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Workspace Organizer transactional live updater\n\n";
    echo "Apply a previously verified candidate:\n";
    echo "  php bin/update_apply.php --transaction=update-... --candidate-dir=/external/releases/candidate-... --apply\n\n";
    echo "Recover an interrupted/failed live mutation from its verified rollback checkpoint:\n";
    echo "  php bin/update_apply.php --transaction=update-... --recover\n\n";
    echo "Optional: --state-root=/external/state --backup-root=/external/backups --json\n";
    echo "Maintenance must already be active and owned by the transaction.\n";
    exit(0);
}

$json = isset($options['json']);

try {
    $result = (new UpdateApplyCommand($root, $json))->execute($options);
    if ($json) {
        echo json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } else {
        $status = (string) ($result['status'] ?? 'ok');
        echo "[OK] {$status}\n";
        if (isset($result['installed_version'])) {
            echo 'Version: ' . $result['installed_version'] . PHP_EOL;
        }
        echo 'Maintenance: ' . (($result['maintenance_active'] ?? true) ? 'ACTIVE' : 'released') . PHP_EOL;
    }
    exit(0);
} catch (UpdateApplyException $e) {
    if ($json) {
        echo json_encode(
            [
                'status' => 'fail',
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ] + $e->details,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } else {
        fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    }
    exit($e->exitCode);
} catch (Throwable $e) {
    if ($json) {
        echo json_encode(
            [
                'status' => 'fail',
                'code' => 'apply_failed',
                'message' => $e->getMessage(),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } else {
        fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
