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

    $rollbackLeaseTransaction = 'update-auto-recovery-rollback-lease';
    automaticRecoveryInitializeJournal($stateRoot, $root, $temp, $rollbackLeaseTransaction);
    $journalPath = $stateRoot . '/transactions/' . $rollbackLeaseTransaction . '.json';
    $journalPayload = json_decode(
        (string) file_get_contents($journalPath),
        true,
        32,
        JSON_THROW_ON_ERROR
    );
    $journalPayload['state'] = 'rollback_verified';
    $journalPayload['live_mutation_started'] = true;
    $journalPayload['updated_at'] = time();
    automaticRecoveryAssert(
        file_put_contents(
            $journalPath,
            json_encode(
                $journalPayload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        ) !== false,
        'Не удалось подготовить rollback_verified journal'
    );

    $maintenance->enter($rollbackLeaseTransaction, 'Проверка stale web-lease после rollback');
    $rollbackContinuation = new UpdateWebContinuation($stateRoot);
    $rollbackContinuation->create($rollbackLeaseTransaction);
    automaticRecoveryAssert(
        $rollbackContinuation->active($rollbackLeaseTransaction),
        'Не удалось подготовить активный web-lease для rollback recovery'
    );

    $rollbackLeaseInvocations = 0;
    $rollbackLeaseRecovery = new UpdateAutomaticRecovery(
        $root,
        static function () use (
            &$rollbackLeaseInvocations,
            $maintenance,
            $rollbackLeaseTransaction
        ): array {
            $rollbackLeaseInvocations++;
            $maintenance->leave($rollbackLeaseTransaction);
            return [
                'code' => 0,
                'stdout' => json_encode([
                    'status' => 'rollback_recovery_verified',
                    'transaction_id' => $rollbackLeaseTransaction,
                    'maintenance_active' => false,
                ], JSON_THROW_ON_ERROR),
                'stderr' => '',
            ];
        }
    );
    $rollbackLeaseResult = $rollbackLeaseRecovery->attempt($maintenance);
    automaticRecoveryAssert(
        $rollbackLeaseResult['status'] === 'recovered',
        'rollback_verified ошибочно заблокирован живым web-lease'
    );
    automaticRecoveryAssert(
        $rollbackLeaseInvocations === 1,
        'Recovery rollback_verified не был запущен немедленно'
    );
    automaticRecoveryAssert(
        !$rollbackContinuation->active($rollbackLeaseTransaction),
        'Завершённый recovery оставил web-lease активным'
    );
    automaticRecoveryAssert(
        !$maintenance->state()['active'],
        'Завершённый rollback recovery оставил maintenance активным'
    );

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

    $applyCommandSource = (string) file_get_contents($root . '/core/UpdateApplyCommand.php');
    automaticRecoveryAssert(
        !str_contains($applyCommandSource, "'apply_error_type' => \$applyError::class"),
        'Recovery rollback снова ссылается на отсутствующую переменную applyError'
    );
    automaticRecoveryAssert(
        str_contains($applyCommandSource, "'recovery_mode' => true")
            && str_contains($applyCommandSource, "'rollback_error_type' => \$rollbackError::class"),
        'Recovery rollback не сохраняет безопасный контекст первичной ошибки'
    );
    automaticRecoveryAssert(
        str_contains($applyCommandSource, 'verifyRollbackState(')
            && str_contains(
                $applyCommandSource,
                "\$this->processRunner::class !== __NAMESPACE__ . '\\\\UpdateInProcessRunner'"
            )
            && str_contains($applyCommandSource, "'verification_mode' => 'cold_process'")
            && str_contains($applyCommandSource, "'verification_mode' => 'web_snapshot'")
            && str_contains($applyCommandSource, "'health_status' => 'rollback_snapshot_verified'"),
        'Rollback не разделяет холодную process-проверку и web-only проверку восстановленного снимка'
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
