<?php

declare(strict_types=1);

use App\Services\MaintenanceModeService;
use Core\UpdateApplyCommand;
use Core\UpdateTransactionJournal;

$root = dirname(__DIR__, 2);

require_once $root . '/core/Version.php';
require_once $root . '/core/UpdateTransactionJournal.php';
require_once $root . '/core/UpdateTransactionStateMachine.php';
require_once $root . '/core/UpdateBackupManager.php';
require_once $root . '/core/UpdateLiveApplier.php';
require_once $root . '/core/UpdateApplyOperationLock.php';
require_once $root . '/core/UpdateRollbackCodeRestorer.php';
require_once $root . '/app/services/MaintenanceModeService.php';
require_once $root . '/core/UpdateApplyCommand.php';

function preapplyCrashAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function preapplyCrashRemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        $current = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($current) && !is_link($current)) {
            preapplyCrashRemoveTree($current);
            continue;
        }
        @unlink($current);
    }
    @rmdir($path);
}

$temp = sys_get_temp_dir() . '/notes-preapply-crash-recovery-' . bin2hex(random_bytes(6));
$stateRoot = $temp . '/state';
$backupRoot = $temp . '/backups';
$stageDir = $temp . '/stage';
$transactionId = 'update-preapply-crash-test';

preapplyCrashAssert(mkdir($stageDir, 0700, true), 'Не удалось создать внешний stage для теста');

try {
    $journal = new UpdateTransactionJournal($stateRoot, $root);
    $state = $journal->initialize([
        'transaction_id' => $transactionId,
        'installed_version' => Core\Version::VERSION,
        'installed_version_code' => Core\Version::VERSION_CODE,
        'target_version' => '1.0.6-preapply-test',
        'target_version_code' => Core\Version::VERSION_CODE + 1,
        'package_sha256' => str_repeat('a', 64),
        'stage_dir' => $stageDir,
    ]);

    preapplyCrashAssert(($state['state'] ?? '') === 'initialized', 'Журнал не зафиксировал initialized');
    preapplyCrashAssert(
        ($state['live_mutation_started'] ?? true) === false,
        'Initialized-транзакция ошибочно помечена как destructive'
    );

    $maintenance = new MaintenanceModeService($stateRoot, $root);
    $maintenance->enter($transactionId, 'CI: аварийный обрыв до backup');

    $result = (new UpdateApplyCommand($root, true))->execute([
        'transaction' => $transactionId,
        'recover' => true,
        'state-root' => $stateRoot,
        'backup-root' => $backupRoot,
    ]);

    preapplyCrashAssert(
        ($result['status'] ?? '') === 'recovered_without_live_mutation',
        'Recovery не распознал безопасный pre-live crash'
    );
    preapplyCrashAssert(
        ($result['state'] ?? '') === 'initialized',
        'Recovery вернул неожиданное состояние ранней транзакции'
    );
    preapplyCrashAssert(
        ($result['maintenance_active'] ?? true) === false,
        'Recovery не подтвердил снятие maintenance'
    );
    preapplyCrashAssert(
        !$maintenance->state()['active'],
        'Maintenance остался активным после раннего crash-recovery'
    );
    preapplyCrashAssert(
        ($journal->load($transactionId)['live_mutation_started'] ?? true) === false,
        'Ранний recovery не должен менять destructive boundary журнала'
    );

    echo "[OK] ранний crash до backup автоматически восстанавливается без ручных действий\n";
} finally {
    preapplyCrashRemoveTree($temp);
}
