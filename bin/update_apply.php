<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/CliRuntime.php';
\Core\CliRuntime::loadEnvironment($root);
require_once $root . '/core/Version.php';
require_once $root . '/core/UpdateTransactionJournal.php';
require_once $root . '/core/UpdateTransactionStateMachine.php';
require_once $root . '/core/UpdateBackupManager.php';
require_once $root . '/core/UpdateLiveApplier.php';
require_once $root . '/core/UpdateApplyOperationLock.php';
require_once $root . '/core/UpdateRollbackCodeRestorer.php';
require_once $root . '/app/services/MaintenanceModeService.php';
require_once $root . '/core/UpdateApplyCommand.php';
require_once $root . '/core/SecurityEventLog.php';

use Core\SecurityEventLog;
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
    echo "Транзакционный updater Workspace Organizer\n\n";
    echo "Применить ранее проверенный candidate:\n";
    echo "  php bin/update_apply.php --transaction=update-... --candidate-dir=/external/releases/candidate-... --apply\n\n";
    echo "Восстановить прерванное/неудачное изменение из проверенной точки отката:\n";
    echo "  php bin/update_apply.php --transaction=update-... --recover\n\n";
    echo "Необязательно: --state-root=/external/state --backup-root=/external/backups --json\n";
    echo "Режим обслуживания должен быть уже активен и принадлежать этой транзакции.\n";
    exit(0);
}

$json = isset($options['json']);

try {
    $result = (new UpdateApplyCommand($root, $json))->execute($options);
    $recovery = isset($options['recover']);
    SecurityEventLog::emit(
        $recovery ? 'update.recovery_succeeded' : 'update.apply_succeeded',
        'info',
        'updater',
        'cli',
        null,
        [
            'transaction_id' => (string) ($options['transaction'] ?? ''),
            'status' => (string) ($result['status'] ?? 'ok'),
            'installed_version' => (string) ($result['installed_version'] ?? ''),
        ]
    );
    if ($json) {
        echo json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } else {
        $status = (string) ($result['status'] ?? 'ok');
        echo "[OK] {$status}\n";
        if (isset($result['installed_version'])) {
            echo 'Версия: ' . $result['installed_version'] . PHP_EOL;
        }
        echo 'Режим обслуживания: ' . (($result['maintenance_active'] ?? true) ? 'АКТИВЕН' : 'снят') . PHP_EOL;
    }
    exit(0);
} catch (UpdateApplyException $e) {
    SecurityEventLog::emit(
        isset($options['recover']) ? 'update.recovery_failed' : 'update.apply_failed',
        'critical',
        'updater',
        'cli',
        null,
        [
            'transaction_id' => (string) ($options['transaction'] ?? ''),
            'error_code' => $e->errorCode,
        ]
    );
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
    SecurityEventLog::emit(
        isset($options['recover']) ? 'update.recovery_failed' : 'update.apply_failed',
        'critical',
        'updater',
        'cli',
        null,
        [
            'transaction_id' => (string) ($options['transaction'] ?? ''),
            'error_type' => $e::class,
        ]
    );
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
