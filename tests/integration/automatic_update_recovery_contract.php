<?php

declare(strict_types=1);

use App\Services\MaintenanceModeService;
use Core\UpdateAutomaticRecovery;
use Core\UpdateCoordinatorLock;
use Core\UpdateTransactionJournal;
use Core\UpdateWebContinuation;

$root = dirname(__DIR__, 2);
require_once $root . '/app/services/MaintenanceModeService.php';
require_once $root . '/core/UpdateAutomaticRecovery.php';
require_once $root . '/core/UpdateBootRecoveryGate.php';
require_once $root . '/core/UpdateCoordinatorLock.php';
require_once $root . '/core/UpdateTransactionJournal.php';
require_once $root . '/core/UpdateWebContinuation.php';

function automaticRecoveryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function automaticRecoveryInitializeJournal(
    string $stateRoot,
    string $root,
    string $temp,
    string $transactionId
): void {
    $stage = $temp . '/stage/' . $transactionId;
    if (!is_dir($stage) && !mkdir($stage, 0700, true) && !is_dir($stage)) {
        throw new RuntimeException('Не удалось создать stage для recovery journal');
    }

    (new UpdateTransactionJournal($stateRoot, $root))->initialize([
        'transaction_id' => $transactionId,
        'installed_version' => '1.0.5-test',
        'installed_version_code' => 10005,
        'target_version' => '1.0.6-test',
        'target_version_code' => 10006,
        'package_sha256' => str_repeat('a', 64),
        'stage_dir' => $stage,
    ]);
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
    automaticRecoveryInitializeJournal($stateRoot, $root, $temp, $successTransaction);
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

    // Если rollback уже доказан журналом, живой browser lease больше не
    // является основанием держать всю установку в 503 до истечения 180 секунд.
    $terminalTransaction = 'update-auto-recovery-terminal';
    automaticRecoveryInitializeJournal($stateRoot, $root, $temp, $terminalTransaction);
    $terminalJournal = new UpdateTransactionJournal($stateRoot, $root);
    $terminalPath = $terminalJournal->path($terminalTransaction);
    $terminalState = json_decode(
        (string) file_get_contents($terminalPath),
        true,
        32,
        JSON_THROW_ON_ERROR
    );
    $terminalState['state'] = 'rollback_verified';
    $terminalState['live_mutation_started'] = true;
    $terminalState['rollback'] = [
        'started_at' => time() - 5,
        'from_state' => 'migrations_applied',
        'failure_code' => 'postcheck_failed',
    ];
    $terminalState['rollback_verify'] = [
        'version' => '1.0.5-test',
        'version_code' => 10005,
        'health_status' => 'ok',
        'verified_at' => time(),
    ];
    file_put_contents(
        $terminalPath,
        json_encode(
            $terminalState,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL
    );

    $maintenance->enter($terminalTransaction, 'Проверка terminal recovery при живом lease');
    $terminalContinuation = new UpdateWebContinuation($stateRoot);
    $terminalContinuation->create($terminalTransaction);
    automaticRecoveryAssert(
        $terminalContinuation->active($terminalTransaction),
        'Контрольный web-lease не активен'
    );

    $terminalInvocations = 0;
    $terminalRecovery = new UpdateAutomaticRecovery(
        $root,
        static function () use (
            &$terminalInvocations,
            $maintenance,
            $terminalTransaction
        ): array {
            $terminalInvocations++;
            $maintenance->leave($terminalTransaction);
            return [
                'code' => 0,
                'stdout' => json_encode([
                    'status' => 'rollback_recovery_verified',
                    'transaction_id' => $terminalTransaction,
                    'maintenance_active' => false,
                    'version' => '1.0.5-test',
                ], JSON_THROW_ON_ERROR),
                'stderr' => '',
            ];
        }
    );
    $terminalResult = $terminalRecovery->attempt($maintenance);
    automaticRecoveryAssert(
        $terminalResult['status'] === 'recovered',
        'Подтверждённый rollback ошибочно ждёт истечения web-lease'
    );
    automaticRecoveryAssert(
        $terminalInvocations === 1,
        'Terminal recovery не был запущен немедленно'
    );
    automaticRecoveryAssert(
        !$maintenance->state()['active'],
        'Terminal recovery не снял maintenance'
    );
    automaticRecoveryAssert(
        !$terminalContinuation->active($terminalTransaction),
        'Terminal recovery не удалил устаревший web-lease'
    );

    $busyTransaction = 'update-auto-recovery-busy';
    automaticRecoveryInitializeJournal($stateRoot, $root, $temp, $busyTransaction);
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

    $staleStartedAt = time() - 3600;
    $freshInstallDate = date('YmdHis', time());
    automaticRecoveryAssert(
        \Core\UpdateBootRecoveryGate::maintenancePredatesCurrentInstallation(
            ['started_at' => $staleStartedAt],
            $freshInstallDate
        ),
        'Maintenance предыдущей установки не распознан по INSTALL_DATE'
    );
    automaticRecoveryAssert(
        !\Core\UpdateBootRecoveryGate::maintenancePredatesCurrentInstallation(
            ['started_at' => time()],
            date('YmdHis', time() - 3600)
        ),
        'Текущая updater-транзакция ошибочно распознана как состояние предыдущей установки'
    );
    automaticRecoveryAssert(
        !\Core\UpdateBootRecoveryGate::maintenancePredatesCurrentInstallation(
            ['started_at' => $staleStartedAt],
            'invalid'
        ),
        'Некорректный INSTALL_DATE не должен автоматически снимать maintenance'
    );

    $bootGateSource = (string) file_get_contents($root . '/core/UpdateBootRecoveryGate.php');
    automaticRecoveryAssert(
        str_contains($bootGateSource, 'maintenancePredatesCurrentInstallation($state)')
            && str_contains($bootGateSource, '$maintenance->leave($transactionId)')
            && str_contains($bootGateSource, 'предыдущей установки'),
        'Boot recovery не содержит безопасного отсечения stale maintenance после fresh install'
    );

    $failedTransaction = 'update-auto-recovery-failed';
    automaticRecoveryInitializeJournal($stateRoot, $root, $temp, $failedTransaction);
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
    automaticRecoveryAssert(($failedResult['code'] ?? '') === 'rollback_failed', 'Потерян безопасный код диагностики recovery');
    automaticRecoveryAssert(
        ($failedResult['transaction_id'] ?? '') === $failedTransaction,
        'Ошибка recovery потеряла ID транзакции для аварийной диагностики'
    );
    automaticRecoveryAssert(
        str_contains($bootGateSource, 'diagnostic_code')
            && str_contains($bootGateSource, 'transaction_id')
            && str_contains($bootGateSource, 'diagnosticMessage'),
        'Ранний recovery-барьер не показывает безопасную аварийную диагностику'
    );
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
