<?php

declare(strict_types=1);

$runtimeRoot = dirname(__DIR__);
require_once $runtimeRoot . '/core/UpdateExternalCliSupport.php';

use Core\SecurityEventLog;
use Core\UpdateApplyCommand;
use Core\UpdateApplyException;
use Core\UpdateExternalCliSupport;

UpdateExternalCliSupport::assertCli('Внешний обновлятор доступен только из CLI.');

$options = getopt('', [
    'app-root:',
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
    echo "Внешний транзакционный обновлятор Workspace Organizer\n\n";
    echo "Применение:\n";
    echo "  php bin/update_external_apply.php --app-root=/path/to/workspace --transaction=update-... --candidate-dir=/external/candidate --apply\n\n";
    echo "Восстановление:\n";
    echo "  php bin/update_external_apply.php --app-root=/path/to/workspace --transaction=update-... --recover\n\n";
    echo "Необязательные параметры: --state-root, --backup-root, --json.\n";
    echo "Runtime обновлятора обязан находиться вне live-tree приложения.\n";
    exit(0);
}

$json = isset($options['json']);
$cli = new UpdateExternalCliSupport($runtimeRoot, $json);
$appRoot = $cli->applicationRoot(
    $options['app-root'] ?? '',
    'unsafe_runtime_layout',
    'invalid_runtime_root',
    'Внешний runtime обновлятора'
);
$runtimeReal = (string) realpath($runtimeRoot);

require_once $runtimeReal . '/core/Environment.php';
try {
    \Core\Environment::load($appRoot . '/.env');
} catch (Throwable $e) {
    $cli->fail(
        'Не удалось загрузить окружение live-установки: ' . $e->getMessage(),
        'environment_failed',
        2
    );
}

require_once $runtimeReal . '/core/Version.php';
require_once $runtimeReal . '/core/UpdateTransactionJournal.php';
require_once $runtimeReal . '/core/UpdateTransactionStateMachine.php';
require_once $runtimeReal . '/core/UpdateBackupManager.php';
require_once $runtimeReal . '/core/UpdateLiveApplier.php';
require_once $runtimeReal . '/core/UpdateApplyOperationLock.php';
require_once $runtimeReal . '/core/UpdateRollbackCodeRestorer.php';
require_once $runtimeReal . '/app/services/MaintenanceModeService.php';
require_once $runtimeReal . '/core/UpdateApplyCommand.php';
require_once $runtimeReal . '/core/SecurityEventLog.php';

/** @var Closure(string,string,array<string,mixed>):void $recordEvent */
$recordEvent = static function (string $event, string $severity, array $context) use ($appRoot): void {
    try {
        (new SecurityEventLog(null, $appRoot))->record(
            $event,
            $severity,
            'updater',
            'cli',
            null,
            $context
        );
    } catch (Throwable $e) {
        error_log('Журнал безопасности обновлятора недоступен: ' . $e->getMessage());
    }
};

$commandOptions = $options;
unset($commandOptions['app-root'], $commandOptions['help'], $commandOptions['json']);

try {
    $result = (new UpdateApplyCommand($appRoot, $json))->execute($commandOptions);
    $recovery = isset($commandOptions['recover']);

    $recordEvent(
        $recovery ? 'update.recovery_succeeded' : 'update.apply_succeeded',
        'info',
        [
            'transaction_id' => (string) ($commandOptions['transaction'] ?? ''),
            'status' => (string) ($result['status'] ?? 'ok'),
            'installed_version' => (string) ($result['installed_version'] ?? ''),
            'external_runtime' => true,
        ]
    );

    if ($json) {
        $cli->writeJson($result + ['external_runtime' => true]);
    } else {
        echo '[OK] ' . (string) ($result['status'] ?? 'завершено') . PHP_EOL;
        echo 'Режим обслуживания: '
            . (!empty($result['maintenance_active']) ? 'активен' : 'снят')
            . PHP_EOL;
    }

    exit(0);
} catch (UpdateApplyException $e) {
    $recordEvent(
        isset($commandOptions['recover']) ? 'update.recovery_failed' : 'update.apply_failed',
        'critical',
        [
            'transaction_id' => (string) ($commandOptions['transaction'] ?? ''),
            'error_code' => $e->errorCode,
            'external_runtime' => true,
        ]
    );

    $cli->fail($e->getMessage(), $e->errorCode, $e->exitCode, $e->details);
} catch (Throwable $e) {
    $recordEvent(
        isset($commandOptions['recover']) ? 'update.recovery_failed' : 'update.apply_failed',
        'critical',
        [
            'transaction_id' => (string) ($commandOptions['transaction'] ?? ''),
            'error_type' => $e::class,
            'external_runtime' => true,
        ]
    );

    $cli->fail($e->getMessage(), 'external_apply_failed');
}
