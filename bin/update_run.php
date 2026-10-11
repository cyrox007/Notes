<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/CliRuntime.php';
\Core\CliRuntime::assertCliHidden();
\Core\CliRuntime::loadEnvironment($root);
require_once $root . '/core/Version.php';
require_once $root . '/core/UpdateProcessRunner.php';
require_once $root . '/core/UpdateTransactionJournal.php';
require_once $root . '/core/UpdateTransactionStateMachine.php';
require_once $root . '/core/UpdateExternalRuntime.php';
require_once $root . '/core/UpdateCoordinatorLock.php';
require_once $root . '/app/services/MaintenanceModeService.php';
require_once $root . '/core/UpdateRunSupport.php';

use App\Services\MaintenanceModeService;
use Core\UpdateCoordinatorLock;
use Core\UpdateExternalRuntime;
use Core\UpdateProcessRunner;
use Core\UpdateRunSubprocessException;
use Core\UpdateRunSupport;
use Core\UpdateTransactionJournal;
use Core\UpdateTransactionStateMachine;
use Core\Version;

$options = getopt('', [
    'transaction:',
    'feed-url:',
    'channel:',
    'stage-root:',
    'state-root:',
    'backup-root:',
    'candidate-root:',
    'expected-version-code:',
    'expected-package-sha256:',
    'recover',
    'yes',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Workspace Organizer — сквозной сценарий установки подписанного обновления\n\n";
    echo "Установить следующее подписанное обновление:\n";
    echo "  php bin/update_run.php --yes [--transaction=update-...] [--json]\n\n";
    echo "Восстановить прерванную транзакцию обновления:\n";
    echo "  php bin/update_run.php --recover --transaction=update-... --yes [--json]\n\n";
    echo "Дополнительные параметры: --feed-url, --channel, --stage-root, --state-root, --backup-root, --candidate-root.\n";
    echo "Для веб-интерфейса доступны привязки подтверждённого релиза: --expected-version-code и --expected-package-sha256.\n";
    echo "Обычный режим проверяет и подготавливает пакет, включает режим обслуживания, создаёт проверенную резервную копию,\n";
    echo "формирует внешний кандидат релиза и передаёт управление транзакционному применению во внешнем runtime.\n";
    echo "Флаг --yes обязателен, потому что команда может переключать рабочий код и запускать миграции.\n";
    exit(0);
}

$json = isset($options['json']);

/** @return never */
function updateRunFail(string $message, string $code = 'update_run_failed', int $exitCode = 1, array $details = []): never
{
    global $json;
    if ($json) {
        echo json_encode([
            'status' => 'fail',
            'code' => $code,
            'message' => $message,
        ] + $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, "[ОШИБКА] {$message}\n");
    }
    exit($exitCode);
}

if (!isset($options['yes'])) {
    updateRunFail(
        'Установка обновления требует явного подтверждения --yes.',
        'confirmation_required',
        2
    );
}

$recover = isset($options['recover']);
$transactionId = trim((string) ($options['transaction'] ?? ''));
if ($recover) {
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1) {
        updateRunFail('Для восстановления нужен корректный --transaction.', 'invalid_transaction', 2);
    }
} elseif ($transactionId === '') {
    $transactionId = 'update-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
} elseif (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1) {
    updateRunFail('Некорректный идентификатор транзакции.', 'invalid_transaction', 2);
}

$expectedVersionRaw = trim((string) ($options['expected-version-code'] ?? ''));
$expectedSha256 = strtolower(trim((string) ($options['expected-package-sha256'] ?? '')));
$hasExpectedVersion = $expectedVersionRaw !== '';
$hasExpectedSha = $expectedSha256 !== '';

if ($hasExpectedVersion !== $hasExpectedSha) {
    updateRunFail(
        'Привязка релиза требует одновременно --expected-version-code и --expected-package-sha256.',
        'invalid_review_binding',
        2
    );
}

$expectedVersionCode = null;
if ($hasExpectedVersion) {
    if (preg_match('/^[1-9][0-9]*$/', $expectedVersionRaw) !== 1
        || preg_match('/^[0-9a-f]{64}$/', $expectedSha256) !== 1) {
        updateRunFail('Некорректная привязка подтверждённого релиза.', 'invalid_review_binding', 2);
    }
    $expectedVersionCode = (int) $expectedVersionRaw;
}

$runner = new UpdateProcessRunner();
$support = new UpdateRunSupport($root, $runner);

if ($recover) {
    try {
        $runtime = $support->recordedExternalRuntime($options, $transactionId);
        $command = $runtime !== null
            ? $support->externalApplyCommand($runtime, $transactionId, $options, true)
            : array_merge(
                $support->baseCommand('update_apply.php'),
                ['--transaction=' . $transactionId, '--recover', '--json']
            );
        if ($runtime === null) {
            $command = $support->appendOption($command, $options, 'state-root');
            $command = $support->appendOption($command, $options, 'backup-root');
        }

        $recovered = $support->runJsonCommand(
            $command,
            $runtime !== null ? (string) $runtime['runtime_root'] : $root,
            1200,
            'восстановление'
        );
        if ($json) {
            echo json_encode([
                'status' => 'recovered',
                'transaction_id' => $transactionId,
                'result' => $recovered,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        } else {
            echo "[OK] Восстановление обновлятора завершено\n";
            echo "Транзакция: {$transactionId}\n";
            echo 'Состояние:   ' . (string) ($recovered['status'] ?? 'recovered') . PHP_EOL;
        }
        exit(0);
    } catch (Throwable $e) {
        updateRunFail($e->getMessage(), 'recovery_failed', max(1, (int) $e->getCode()));
    }
}

$maintenanceEntered = false;
$applyInvoked = false;
$stateRoot = trim((string) ($options['state-root'] ?? ''));

try {
    $doctorCommand = $support->baseCommand('update_doctor.php');
    $doctorCommand[] = '--json';
    $readiness = $support->runJsonCommand($doctorCommand, $root, 30, 'проверка готовности');
    if (empty($readiness['ready_for_apply'])) {
        throw new RuntimeException('Проверка готовности не разрешила установку обновления');
    }

    $stageCommand = $support->baseCommand('update_remote.php');
    $stageCommand[] = '--json';
    foreach (['feed-url', 'channel', 'stage-root'] as $option) {
        $stageCommand = $support->appendOption($stageCommand, $options, $option);
    }
    $staged = $support->runJsonCommand($stageCommand, $root, 900, 'подготовка пакета');
    if (($staged['status'] ?? null) !== 'staged') {
        throw new RuntimeException('Обновлятор не вернул проверенный подготовленный пакет');
    }
    $stageDir = trim((string) ($staged['stage_dir'] ?? ''));
    if ($stageDir === '') {
        throw new RuntimeException('Не найден путь к проверенному подготовленному пакету');
    }

    if ($expectedVersionCode !== null) {
        $stagedVersionCode = (int) ($staged['target_version_code'] ?? 0);
        $stagedSha256 = strtolower(trim((string) ($staged['package_sha256'] ?? '')));
        if ($stagedVersionCode !== $expectedVersionCode || !hash_equals($expectedSha256, $stagedSha256)) {
            throw new RuntimeException(
                'Подписанный канал изменился после подтверждения обновления. Повторите проверку в админ-панели.'
            );
        }
    }

    $maintenance = new MaintenanceModeService($stateRoot !== '' ? $stateRoot : null, $root);
    $resolvedStateRoot = $maintenance->configuredStateRoot();
    if (!is_string($resolvedStateRoot) || trim($resolvedStateRoot) === '') {
        throw new RuntimeException('Не удалось определить внешний каталог журнала обновления');
    }
    $stateRoot = rtrim($resolvedStateRoot, '/\\');
    $options['state-root'] = $stateRoot;

    $targetVersion = trim((string) ($staged['target_version'] ?? ''));
    $targetVersionCode = (int) ($staged['target_version_code'] ?? 0);
    $packageSha256 = strtolower(trim((string) ($staged['package_sha256'] ?? '')));
    if ($targetVersion === ''
        || $targetVersionCode <= Version::VERSION_CODE
        || preg_match('/^[0-9a-f]{64}$/', $packageSha256) !== 1) {
        throw new RuntimeException('Подготовленный пакет не содержит корректную идентичность транзакции');
    }

    // Журнал создаётся до maintenance. Поэтому даже аварийное завершение между
    // включением обслуживания и резервным копированием имеет безопасное
    // pre-live состояние, которое следующий запрос сможет закрыть автоматически.
    $journal = new UpdateTransactionJournal($stateRoot, $root);
    $journalState = $journal->initialize([
        'transaction_id' => $transactionId,
        'installed_version' => Version::VERSION,
        'installed_version_code' => Version::VERSION_CODE,
        'target_version' => $targetVersion,
        'target_version_code' => $targetVersionCode,
        'package_sha256' => $packageSha256,
        'stage_dir' => $stageDir,
    ]);
    if (($journalState['live_mutation_started'] ?? true) !== false) {
        throw new RuntimeException('Транзакция уже пересекла destructive boundary');
    }

    // Coordinator-lock удерживается родительским updater на всём участке,
    // где maintenance уже может быть виден другим HTTP-запросам. При гибели
    // процесса flock освобождается ОС, и boot-recovery получает право продолжить.
    $coordinatorLock = new UpdateCoordinatorLock($stateRoot, $transactionId);

    $maintenance->enter($transactionId, 'Обновление Workspace Organizer');
    $maintenanceEntered = true;

    $backupCommand = $support->baseCommand('update_backup.php');
    $backupCommand[] = '--transaction=' . $transactionId;
    $backupCommand[] = '--stage-dir=' . $stageDir;
    $backupCommand[] = '--json';
    foreach (['state-root', 'backup-root'] as $option) {
        $backupCommand = $support->appendOption($backupCommand, $options, $option);
    }
    $backup = $support->runJsonCommand($backupCommand, $root, 1200, 'резервная копия');
    if (($backup['status'] ?? null) !== 'backup_verified') {
        throw new RuntimeException('Резервная копия обновления не прошла проверку');
    }

    $candidateCommand = $support->baseCommand('update_candidate.php');
    $candidateCommand[] = '--transaction=' . $transactionId;
    $candidateCommand[] = '--json';
    foreach (['state-root', 'candidate-root'] as $option) {
        $candidateCommand = $support->appendOption($candidateCommand, $options, $option);
    }
    $candidate = $support->runJsonCommand($candidateCommand, $root, 900, 'кандидат релиза');
    if (($candidate['status'] ?? null) !== 'candidate_verified') {
        throw new RuntimeException('Кандидат релиза не прошёл проверку');
    }
    $candidateDir = trim((string) ($candidate['candidate_dir'] ?? ''));
    if ($candidateDir === '') {
        throw new RuntimeException('Не найден путь к проверенному кандидату релиза');
    }

    $externalRuntime = (new UpdateExternalRuntime($root))->prepare();
    $journalState = (new UpdateTransactionStateMachine($stateRoot, $root))
        ->attachExternalRuntime($transactionId, $externalRuntime);
    $externalRuntime = (new UpdateExternalRuntime($root))->verifyRecorded(
        (array) ($journalState['external_runtime'] ?? []),
        Version::VERSION_CODE
    );

    $applyCommand = $support->externalApplyCommand(
        $externalRuntime,
        $transactionId,
        $options,
        false,
        $candidateDir
    );

    // После этой точки destructive-фаза, откат и снятие maintenance принадлежат
    // только внешнему UpdateApplyCommand. Живые bin/core больше не являются
    // местом исполнения destructive updater-процесса.
    $applyInvoked = true;
    $applied = $support->runJsonCommand(
        $applyCommand,
        (string) $externalRuntime['runtime_root'],
        1800,
        'применение обновления'
    );

    if ($json) {
        echo json_encode([
            'status' => 'committed',
            'transaction_id' => $transactionId,
            'target_version' => $staged['target_version'] ?? null,
            'target_version_code' => $staged['target_version_code'] ?? null,
            'package_sha256' => $staged['package_sha256'] ?? null,
            'apply' => $applied,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        echo "[OK] Подписанное обновление установлено\n";
        echo "Транзакция: {$transactionId}\n";
        echo 'Версия:      ' . (string) ($applied['installed_version'] ?? ($staged['target_version'] ?? 'неизвестно')) . PHP_EOL;
        echo "Резервная точка и журнал транзакции проверены до изменения рабочих файлов.\n";
    }
    exit(0);
} catch (Throwable $e) {
    if ($maintenanceEntered && !$applyInvoked) {
        try {
            $maintenance = new MaintenanceModeService($stateRoot !== '' ? $stateRoot : null, $root);
            $maintenance->leave($transactionId);
        } catch (Throwable $leaveError) {
            updateRunFail(
                $e->getMessage() . '; дополнительно не удалось снять pre-mutation maintenance: ' . $leaveError->getMessage(),
                'preapply_cleanup_failed',
                max(1, (int) $e->getCode()),
                ['transaction_id' => $transactionId, 'maintenance_may_be_active' => true]
            );
        }
    }

    // Обёртка не должна самовольно открывать запись при ошибке: после передачи
    // destructive-фазы внешнему runtime она либо принимает проверенный результат,
    // либо запускает recovery, оставляя maintenance активным при неуспехе recovery.
    if ($applyInvoked) {
        $applyPayload = $e instanceof UpdateRunSubprocessException ? $e->payload : [];
        $applyErrorCode = (string) ($applyPayload['code'] ?? '');
        $maintenanceActive = ($applyPayload['maintenance_active'] ?? null) === true;

        // Штатный UpdateApplyCommand уже выполнил и проверил откат. Повторный
        // --recover здесь не нужен и после снятия maintenance был бы ошибкой.
        if ($applyErrorCode === 'apply_rolled_back' && !$maintenanceActive) {
            updateRunFail(
                'Установка обновления завершилась ошибкой, исходная версия автоматически восстановлена.',
                'apply_failed_recovered',
                max(1, (int) $e->getCode()),
                [
                    'transaction_id' => $transactionId,
                    'apply_invoked' => true,
                    'automatic_recovery' => true,
                    'recovery_status' => 'rollback_verified',
                    'recovery_attempt' => 0,
                    'apply_error' => $e->getMessage(),
                    'maintenance_may_be_active' => false,
                ]
            );
        }

        // Структурированная ошибка до destructive boundary уже очищена самим
        // UpdateApplyCommand. Автовосстановление требуется только при активном
        // maintenance либо при обрыве/таймауте без достоверного JSON-результата.
        $structuredSafeFailure = $e instanceof UpdateRunSubprocessException
            && !$maintenanceActive
            && !in_array($applyErrorCode, ['rollback_failed', 'maintenance_release_failed'], true);
        if ($structuredSafeFailure) {
            updateRunFail(
                $e->getMessage(),
                'apply_failed_before_mutation',
                max(1, (int) $e->getCode()),
                [
                    'transaction_id' => $transactionId,
                    'apply_invoked' => true,
                    'automatic_recovery' => false,
                    'maintenance_may_be_active' => false,
                ]
            );
        }

        try {
            $recovery = $support->automaticRecover(
                $options,
                $transactionId,
                $externalRuntime ?? null
            );
            $recoveryStatus = (string) ($recovery['status'] ?? 'recovered');

            // Если применение было committed, а упало только снятие maintenance,
            // восстановление подтверждает новую версию и завершает исходное
            // действие как успешное обновление.
            if ($recoveryStatus === 'committed_recovery_verified') {
                if ($json) {
                    echo json_encode([
                        'status' => 'committed',
                        'transaction_id' => $transactionId,
                        'target_version' => $staged['target_version'] ?? null,
                        'target_version_code' => $staged['target_version_code'] ?? null,
                        'package_sha256' => $staged['package_sha256'] ?? null,
                        'apply' => $recovery,
                        'automatic_recovery' => true,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
                } else {
                    echo "[OK] Обновление установлено и автоматически доведено до рабочего состояния\n";
                    echo "Транзакция: {$transactionId}\n";
                }
                exit(0);
            }

            updateRunFail(
                'Установка обновления завершилась ошибкой, исходная версия автоматически восстановлена.',
                'apply_failed_recovered',
                max(1, (int) $e->getCode()),
                [
                    'transaction_id' => $transactionId,
                    'apply_invoked' => true,
                    'automatic_recovery' => true,
                    'recovery_status' => $recoveryStatus,
                    'recovery_attempt' => (int) ($recovery['automatic_recovery_attempt'] ?? 1),
                    'apply_error' => $e->getMessage(),
                    'maintenance_may_be_active' => false,
                ]
            );
        } catch (Throwable $recoveryError) {
            updateRunFail(
                'Установка обновления завершилась ошибкой, автоматическое восстановление не удалось завершить: '
                    . $recoveryError->getMessage(),
                'automatic_recovery_failed',
                max(1, (int) $recoveryError->getCode()),
                [
                    'transaction_id' => $transactionId,
                    'apply_invoked' => true,
                    'automatic_recovery' => false,
                    'apply_error' => $e->getMessage(),
                    'maintenance_may_be_active' => true,
                ]
            );
        }
    }

    updateRunFail(
        $e->getMessage(),
        'preapply_failed',
        max(1, (int) $e->getCode()),
        [
            'transaction_id' => $transactionId,
            'apply_invoked' => false,
            'automatic_recovery' => false,
        ]
    );
}
