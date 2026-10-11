<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/CliRuntime.php';
\Core\CliRuntime::assertCliHidden();
\Core\CliRuntime::loadEnvironment($root);
require_once $root . '/core/UpdateArtifactCleaner.php';
require_once $root . '/core/SecurityEventLog.php';
require_once $root . '/app/services/MaintenanceModeService.php';

use App\Services\MaintenanceModeService;
use Core\SecurityEventLog;
use Core\UpdateArtifactCleaner;

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
    echo "Очистка старых артефактов updater Workspace Organizer\n\n";
    echo "Предпросмотр старых завершённых артефактов восстановления (по умолчанию старше 30 дней, сохранить 2 новых):\n";
    echo "  php bin/update_retention.php [--older-than-days=30] [--keep=2] [--json]\n\n";
    echo "Применить очистку:\n";
    echo "  php bin/update_retention.php --apply --yes [--older-than-days=30] [--keep=2] [--json]\n\n";
    echo "Удаляться могут только резервные копии и кандидаты транзакций committed/rollback_verified.\n";
    echo "Журналы транзакций, активное/незавершённое восстановление и staged-пакеты сохраняются.\n";
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
        fwrite(STDERR, "[ОШИБКА] {$message}\n");
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
    updateRetentionFail("{$env} не настроен, а PRIVATE_STORAGE_PATH недоступен", 'path_not_configured', 2);
}

function updateRetentionInt(array $options, string $name, int $default): int
{
    if (!array_key_exists($name, $options)) {
        return $default;
    }
    $raw = trim((string) $options[$name]);
    if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
        updateRetentionFail("--{$name} должен быть неотрицательным целым числом", 'invalid_option', 2);
    }
    return (int) $raw;
}

$apply = isset($options['apply']);
if ($apply && !isset($options['yes'])) {
    updateRetentionFail(
        'Удаление артефактов updater требует явного подтверждения --apply --yes.',
        'confirmation_required',
        2
    );
}
if (!$apply && isset($options['yes'])) {
    updateRetentionFail('--yes допустим только вместе с --apply', 'invalid_option', 2);
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
                'Очистка артефактов updater запрещена при активном режиме обслуживания.',
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

    echo $apply ? "[OK] Очистка старых артефактов updater выполнена\n" : "[OK] Предпросмотр очистки артефактов updater\n";
    echo 'Завершённых транзакций: ' . (int) ($result['terminal_transactions'] ?? 0) . PHP_EOL;
    echo 'Доступно для очистки:    ' . (int) ($result['eligible_transactions'] ?? 0) . PHP_EOL;
    if ($apply) {
        echo 'Удалено каталогов:       ' . (int) ($result['deleted_directories'] ?? 0) . PHP_EOL;
        echo 'Удалено байт:            ' . (int) ($result['deleted_bytes'] ?? 0) . PHP_EOL;
    } else {
        echo "Файлы не удалялись. После проверки предпросмотра используйте --apply --yes.\n";
    }
} catch (Throwable $e) {
    updateRetentionFail($e->getMessage());
}
