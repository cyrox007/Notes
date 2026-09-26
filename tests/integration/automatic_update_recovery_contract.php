<?php

declare(strict_types=1);

use App\Services\MaintenanceModeService;
use Core\UpdateAutomaticRecovery;
use Core\UpdateCoordinatorLock;

$root = dirname(__DIR__, 2);
require_once $root . '/app/services/MaintenanceModeService.php';
require_once $root . '/core/UpdateAutomaticRecovery.php';
require_once $root . '/core/UpdateCoordinatorLock.php';

function automaticRecoveryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function automaticRecoveryRemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        $current = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($current) && !is_link($current)) {
            automaticRecoveryRemoveTree($current);
            continue;
        }
        @unlink($current);
    }
    @rmdir($path);
}

$temp = sys_get_temp_dir() . '/notes-automatic-update-recovery-' . bin2hex(random_bytes(6));
$stateRoot = $temp . '/state';
automaticRecoveryAssert(mkdir($stateRoot, 0700, true), 'Не удалось создать временный каталог состояния');

try {
    $maintenance = new MaintenanceModeService($stateRoot, $root);

    $successTransaction = 'update-auto-recovery-success';
    $maintenance->enter($successTransaction, 'Проверка автоматического восстановления');

    $invocations = 0;
    $recovery = new UpdateAutomaticRecovery(
        $root,
        static function (array $command, string $cwd, int $timeout) use (
            &$invocations,
            $maintenance,
            $successTransaction,
            $root,
            $stateRoot
        ): array {
            $invocations++;
            automaticRecoveryAssert($cwd === $root, 'Recovery запущен не из корня приложения');
            automaticRecoveryAssert($timeout === 1200, 'Recovery использует неожиданный timeout');
            automaticRecoveryAssert(
                in_array('--transaction=' . $successTransaction, $command, true),
                'Recovery не привязан к maintenance-транзакции'
            );
            automaticRecoveryAssert(in_array('--recover', $command, true), 'Recovery не передаёт --recover');
            automaticRecoveryAssert(in_array('--json', $command, true), 'Recovery не запрашивает JSON');
            automaticRecoveryAssert(
                in_array('--state-root=' . $stateRoot, $command, true),
                'Recovery не закрепляет внешний state root'
            );
            automaticRecoveryAssert(
                str_ends_with(str_replace('\\', '/', $command[1] ?? ''), '/bin/update_apply.php'),
                'Recovery должен запускать транзакционный update_apply.php'
            );

            $maintenance->leave($successTransaction);
            return [
                'code' => 0,
                'stdout' => json_encode([
                    'status' => 'rolled_back',
                    'transaction_id' => $successTransaction,
                    'maintenance_active' => false,
                ], JSON_THROW_ON_ERROR),
                'stderr' => '',
            ];
        }
    );

    $result = $recovery->attempt($maintenance);
    automaticRecoveryAssert($result['status'] === 'recovered', 'Успешный recovery не распознан');
    automaticRecoveryAssert($invocations === 1, 'Recovery должен запускаться ровно один раз');
    automaticRecoveryAssert(!$maintenance->state()['active'], 'Maintenance не снят после recovery');

    $busyTransaction = 'update-auto-recovery-busy';
    $maintenance->enter($busyTransaction, 'Проверка конкурентного обновления');
    $busyCoordinator = new UpdateCoordinatorLock($stateRoot, $busyTransaction);
    $busyInvocations = 0;
    $busy = new UpdateAutomaticRecovery(
        $root,
        static function () use (&$busyInvocations): array {
            $busyInvocations++;
            return ['code' => 0, 'stdout' => '{}', 'stderr' => ''];
        }
    );
    $busyResult = $busy->attempt($maintenance);
    automaticRecoveryAssert(
        $busyResult['status'] === 'in_progress',
        'Живое конкурентное обновление не распознано как выполняющееся'
    );
    automaticRecoveryAssert(
        ($busyResult['code'] ?? '') === 'operation_busy',
        'Занятый coordinator-lock не вернул operation_busy'
    );
    automaticRecoveryAssert(
        $busyInvocations === 0,
        'Recovery subprocess не должен запускаться поверх живого updater'
    );
    automaticRecoveryAssert(
        $maintenance->state()['active'],
        'Конкурентный recovery не должен снимать чужой maintenance'
    );
    $busyCoordinator->release();
    $maintenance->leave($busyTransaction);

    $failedTransaction = 'update-auto-recovery-failed';
    $maintenance->enter($failedTransaction, 'Проверка ошибки восстановления');
    $failed = new UpdateAutomaticRecovery(
        $root,
        static fn (): array => [
            'code' => 1,
            'stdout' => json_encode([
                'status' => 'fail',
                'code' => 'rollback_failed',
                'message' => 'Контрольная ошибка rollback',
                'maintenance_active' => true,
            ], JSON_THROW_ON_ERROR),
            'stderr' => '',
        ]
    );
    $failedResult = $failed->attempt($maintenance);
    automaticRecoveryAssert($failedResult['status'] === 'failed', 'Ошибка recovery не распознана');
    automaticRecoveryAssert(
        $maintenance->state()['active'],
        'При неподтверждённом recovery maintenance обязан остаться активным'
    );
    $maintenance->leave($failedTransaction);

    $invalidTransaction = 'update-auto-recovery-invalid';
    $state = $maintenance->enter($invalidTransaction, 'Проверка повреждённого marker');
    $statePath = (string) $state['state_path'];
    automaticRecoveryAssert(file_put_contents($statePath, "{broken\n") !== false, 'Не удалось повредить marker');

    $invalidInvocations = 0;
    $invalid = new UpdateAutomaticRecovery(
        $root,
        static function () use (&$invalidInvocations): array {
            $invalidInvocations++;
            return ['code' => 0, 'stdout' => '{}', 'stderr' => ''];
        }
    );
    $invalidResult = $invalid->attempt($maintenance);
    automaticRecoveryAssert($invalidResult['status'] === 'blocked', 'Повреждённый marker должен блокировать recovery');
    automaticRecoveryAssert($invalidInvocations === 0, 'Повреждённый marker не должен запускать subprocess');
    automaticRecoveryAssert($maintenance->state()['active'], 'Повреждённый marker должен оставаться fail-closed');

    echo "[OK] автоматическое восстановление оборванного обновления закреплено контрактом\n";
} finally {
    automaticRecoveryRemoveTree($temp);
}
