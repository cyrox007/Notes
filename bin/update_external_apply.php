<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Внешний обновлятор доступен только из CLI.\n");
    exit(2);
}

$runtimeRoot = dirname(__DIR__);

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

/** @return never */
function externalApplyFail(string $message, string $code = 'external_apply_failed', int $exitCode = 1, array $details = []): never
{
    global $json;

    if ($json) {
        echo json_encode(
            ['status' => 'fail', 'code' => $code, 'message' => $message] + $details,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . PHP_EOL;
    } else {
        fwrite(STDERR, '[ОШИБКА] ' . $message . PHP_EOL);
    }

    exit($exitCode);
}

function externalApplyNormalize(string $path): string
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
}

function externalApplyInside(string $path, string $parent): bool
{
    $path = externalApplyNormalize($path);
    $parent = externalApplyNormalize($parent);

    return $path === $parent || str_starts_with($path . '/', $parent . '/');
}

$appRootInput = trim((string) ($options['app-root'] ?? ''));
$appRoot = $appRootInput !== '' ? realpath($appRootInput) : false;
$runtimeReal = realpath($runtimeRoot);

if (!is_string($appRoot) || !is_dir($appRoot) || is_link($appRootInput)) {
    externalApplyFail('Нужен существующий безопасный --app-root', 'invalid_app_root', 2);
}
if (!is_string($runtimeReal) || !is_dir($runtimeReal) || is_link($runtimeRoot)) {
    externalApplyFail('Внешний runtime обновлятора недоступен', 'invalid_runtime_root', 2);
}
if (externalApplyInside($runtimeReal, $appRoot) || externalApplyInside($appRoot, $runtimeReal)) {
    externalApplyFail(
        'Внешний runtime и live-приложение должны находиться в разных деревьях каталогов',
        'unsafe_runtime_layout',
        2
    );
}

require_once $runtimeReal . '/core/Environment.php';
try {
    \Core\Environment::load($appRoot . '/.env');
} catch (Throwable $e) {
    externalApplyFail('Не удалось загрузить окружение live-установки: ' . $e->getMessage(), 'environment_failed', 2);
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

use Core\SecurityEventLog;
use Core\UpdateApplyCommand;
use Core\UpdateApplyException;

/** @param array<string,mixed> $context */
function externalApplyEvent(string $event, string $severity, string $appRoot, array $context): void
{
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
}

$commandOptions = $options;
unset($commandOptions['app-root'], $commandOptions['help'], $commandOptions['json']);

try {
    $result = (new UpdateApplyCommand($appRoot, $json))->execute($commandOptions);
    $recovery = isset($commandOptions['recover']);

    externalApplyEvent(
        $recovery ? 'update.recovery_succeeded' : 'update.apply_succeeded',
        'info',
        $appRoot,
        [
            'transaction_id' => (string) ($commandOptions['transaction'] ?? ''),
            'status' => (string) ($result['status'] ?? 'ok'),
            'installed_version' => (string) ($result['installed_version'] ?? ''),
            'external_runtime' => true,
        ]
    );

    if ($json) {
        echo json_encode(
            $result + ['external_runtime' => true],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } else {
        echo '[OK] ' . (string) ($result['status'] ?? 'завершено') . PHP_EOL;
        echo 'Maintenance: ' . (!empty($result['maintenance_active']) ? 'активен' : 'снят') . PHP_EOL;
    }

    exit(0);
} catch (UpdateApplyException $e) {
    externalApplyEvent(
        isset($commandOptions['recover']) ? 'update.recovery_failed' : 'update.apply_failed',
        'critical',
        $appRoot,
        [
            'transaction_id' => (string) ($commandOptions['transaction'] ?? ''),
            'error_code' => $e->errorCode,
            'external_runtime' => true,
        ]
    );

    externalApplyFail($e->getMessage(), $e->errorCode, $e->exitCode, $e->details);
} catch (Throwable $e) {
    externalApplyEvent(
        isset($commandOptions['recover']) ? 'update.recovery_failed' : 'update.apply_failed',
        'critical',
        $appRoot,
        [
            'transaction_id' => (string) ($commandOptions['transaction'] ?? ''),
            'error_type' => $e::class,
            'external_runtime' => true,
        ]
    );

    externalApplyFail($e->getMessage());
}
