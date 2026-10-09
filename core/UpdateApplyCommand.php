<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/MigrationManifest.php';
require_once __DIR__ . '/UpdateMigrationPreflight.php';
require_once __DIR__ . '/UpdateCommandRunner.php';
require_once __DIR__ . '/UpdateProcessRunner.php';
require_once __DIR__ . '/UpdateApplyOperationLock.php';
require_once __DIR__ . '/UpdateLiveApplier.php';
require_once __DIR__ . '/UpdateRollbackCodeRestorer.php';
require_once __DIR__ . '/ServiceLog.php';
require_once __DIR__ . '/UpdateStepBudget.php';

use App\Services\MaintenanceModeService;
use mysqli;
use RuntimeException;
use Throwable;

/**
 * Orchestrates the destructive half of a signed updater transaction.
 *
 * All durable state lives in UpdateTransactionJournal. This command is safe to
 * restart after process death: rollback resumes from rollback_started,
 * code_restored or database_restored instead of assuming try/catch continuity.
 */
final class UpdateApplyCommand
{
    private string $appRoot;
    private bool $json;
    private UpdateCommandRunner $processRunner;
    private ?UpdateStepBudget $budget = null;

    public function __construct(string $appRoot, bool $json = false, ?UpdateCommandRunner $processRunner = null)
    {
        $real = realpath($appRoot);
        if (!is_string($real) || !is_dir($real) || is_link($appRoot)) {
            throw new RuntimeException('Application root cannot be resolved safely for updater apply');
        }
        $this->appRoot = rtrim($real, '/\\');
        $this->json = $json;
        $this->processRunner = $processRunner ?? new UpdateProcessRunner();
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function execute(array $options): array
    {
        $apply = array_key_exists('apply', $options);
        $recover = array_key_exists('recover', $options);
        if ($apply === $recover) {
            throw new UpdateApplyException('Exactly one of --apply or --recover is required', 'invalid_action', 2);
        }

        $transactionId = trim((string) ($options['transaction'] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1) {
            throw new UpdateApplyException('A valid --transaction id is required', 'invalid_transaction', 2);
        }

        $stateRoot = $this->resolveRoot((string) ($options['state-root'] ?? ''), 'UPDATE_STATE_PATH', 'updates');
        $backupRoot = $this->resolveRoot((string) ($options['backup-root'] ?? ''), 'UPDATE_BACKUP_PATH', 'update-backups');
        $candidateOption = trim((string) ($options['candidate-dir'] ?? ''));
        $singleStep = array_key_exists('single-step', $options);
        $this->budget = $singleStep ? new UpdateStepBudget() : null;

        try {
            // Intentionally retained in this function scope so its flock covers
            // maintenance validation, apply/recover, rollback and marker release.
            $operationLock = new UpdateApplyOperationLock($stateRoot, $transactionId);
        } catch (UpdateOperationBusyException $e) {
            throw new UpdateApplyException(
                'Another live apply/recovery process already owns this updater transaction',
                'operation_busy',
                75
            );
        }

        $maintenance = new MaintenanceModeService($stateRoot, $this->appRoot);
        $maintenanceState = $maintenance->state();
        if (!$maintenanceState['active'] || !$maintenanceState['valid']) {
            throw new UpdateApplyException('Transactional updater requires active valid maintenance', 'maintenance_required');
        }
        if (!hash_equals($transactionId, (string) $maintenanceState['transaction_id'])) {
            throw new UpdateApplyException('Maintenance mode belongs to another updater transaction', 'maintenance_owner_mismatch');
        }

        $stateMachine = new UpdateTransactionStateMachine($stateRoot, $this->appRoot);
        $journalState = $stateMachine->load($transactionId);
        $applier = new UpdateLiveApplier($this->appRoot);
        $mutablePaths = $this->mutablePaths();
        $applier->assertMutablePathsSwitchSafe($mutablePaths);
        $backupManager = new UpdateBackupManager($backupRoot, $this->appRoot, $mutablePaths);

        if ($recover) {
            try {
                return $this->recover(
                $transactionId,
                $journalState,
                $maintenance,
                $stateMachine,
                $applier,
                $backupManager
                );
            } catch (UpdateStepPending $pause) {
                return $pause->result($transactionId);
            }
        }

        if ($candidateOption === '') {
            throw new UpdateApplyException('--candidate-dir is required with --apply', 'candidate_required', 2);
        }

        try {
            return $this->apply(
                $transactionId,
                $candidateOption,
                $journalState,
                $maintenance,
                $stateMachine,
                $applier,
                $backupManager,
                $singleStep
            );
        } catch (UpdateStepPending $pause) {
            return $pause->result($transactionId);
        } catch (Throwable $e) {
            $latest = $stateMachine->load($transactionId);
            if (($latest['live_mutation_started'] ?? false) !== true) {
                try {
                    $maintenance->leave($transactionId);
                } catch (Throwable $leaveError) {
                    throw new UpdateApplyException(
                        'Проверка обновления завершилась ошибкой до изменения рабочих файлов, '
                        . 'но режим обслуживания не удалось снять: ' . $leaveError->getMessage(),
                        'maintenance_release_failed',
                        1,
                        [
                            'apply_error' => $e->getMessage(),
                            'transaction_state' => (string) ($latest['state'] ?? ''),
                            'maintenance_active' => true,
                        ]
                    );
                }
            }

            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $journalState
     * @return array<string,mixed>
     */
    private function recover(
        string $transactionId,
        array $journalState,
        MaintenanceModeService $maintenance,
        UpdateTransactionStateMachine $stateMachine,
        UpdateLiveApplier $applier,
        UpdateBackupManager $backupManager
    ): array {
        $state = (string) ($journalState['state'] ?? '');
        $liveMutation = ($journalState['live_mutation_started'] ?? false) === true;

        if (!$liveMutation && in_array($state, ['initialized', 'backup_verified', 'candidate_verified', 'preflight_verified'], true)) {
            $maintenance->leave($transactionId);
            return [
                'status' => 'recovered_without_live_mutation',
                'transaction_id' => $transactionId,
                'state' => $state,
                'maintenance_active' => false,
            ];
        }

        if ($state === 'committed') {
            $version = $applier->readLiveVersion();
            $this->assertVersion($journalState, $version, true, 'Committed transaction does not match live target version');
            $health = $this->health($this->appRoot);
            $migrationStatus = $this->migrationStatus(
                $this->appRoot,
                (int) ($journalState['target_version_code'] ?? 0)
            );
            try {
                $maintenance->leave($transactionId);
            } catch (Throwable $e) {
                throw new UpdateApplyException(
                    'Committed update is verified but maintenance could not be released: ' . $e->getMessage(),
                    'maintenance_release_failed',
                    1,
                    [
                        'transaction_state' => 'committed',
                        'maintenance_active' => true,
                        'health_status' => $health['status'] ?? null,
                        'migration_status_sha256' => hash('sha256', $migrationStatus['stdout']),
                    ]
                );
            }
            return [
                'status' => 'committed_recovery_verified',
                'transaction_id' => $transactionId,
                'maintenance_active' => false,
                'version' => $version['version'],
            ];
        }

        if ($state === 'rollback_verified') {
            $version = $applier->readLiveVersion();
            $this->assertVersion($journalState, $version, false, 'Verified rollback no longer matches installed version');
            $health = $this->health($this->appRoot);
            $migrationStatus = $this->migrationStatus(
                $this->appRoot,
                (int) ($journalState['installed_version_code'] ?? 0)
            );
            try {
                $maintenance->leave($transactionId);
            } catch (Throwable $e) {
                throw new UpdateApplyException(
                    'Verified rollback is healthy but maintenance could not be released: ' . $e->getMessage(),
                    'maintenance_release_failed',
                    1,
                    [
                        'transaction_state' => 'rollback_verified',
                        'maintenance_active' => true,
                        'health_status' => $health['status'] ?? null,
                        'migration_status_sha256' => hash('sha256', $migrationStatus['stdout']),
                    ]
                );
            }
            return [
                'status' => 'rollback_recovery_verified',
                'transaction_id' => $transactionId,
                'maintenance_active' => false,
                'version' => $version['version'],
            ];
        }

        if (!$liveMutation) {
            throw new UpdateApplyException(
                "Transaction state {$state} cannot be recovered automatically before live mutation",
                'invalid_recovery_state'
            );
        }

        try {
            $restartWs = (bool) ($journalState['preflight']['ws_was_running'] ?? false);
            $verified = $this->rollback(
                $transactionId,
                $journalState,
                $stateMachine,
                $applier,
                $backupManager,
                $restartWs
            );
        } catch (UpdateStepPending $pause) {
            throw $pause;
        } catch (Throwable $rollbackError) {
            $this->logService('updater.rollback_failed', 'critical', $transactionId, [
                'recovery_mode' => true,
                'rollback_error_type' => $rollbackError::class,
                'message' => $rollbackError->getMessage(),
            ]);
            $this->recordRollbackFailure($stateMachine, $transactionId, [
                'rollback_error' => $rollbackError->getMessage(),
                'at' => time(),
            ]);
            throw new UpdateApplyException(
                'Rollback failed; maintenance remains active: ' . $rollbackError->getMessage(),
                'rollback_failed',
                1,
                ['maintenance_active' => true]
            );
        }

        try {
            $maintenance->leave($transactionId);
        } catch (Throwable $e) {
            // rollback_verified is a successful terminal recovery state. Never
            // downgrade it to rollback_failed merely because marker cleanup failed.
            throw new UpdateApplyException(
                'Rollback is verified but maintenance could not be released: ' . $e->getMessage(),
                'maintenance_release_failed',
                1,
                [
                    'transaction_state' => 'rollback_verified',
                    'rollback' => $verified,
                    'maintenance_active' => true,
                ]
            );
        }

        return [
            'status' => 'rolled_back',
            'transaction_id' => $transactionId,
            'rollback' => $verified,
            'maintenance_active' => false,
        ];
    }

    /**
     * @param array<string,mixed> $journalState
     * @return array<string,mixed>
     */
    private function apply(
        string $transactionId,
        string $candidateOption,
        array $journalState,
        MaintenanceModeService $maintenance,
        UpdateTransactionStateMachine $stateMachine,
        UpdateLiveApplier $applier,
        UpdateBackupManager $backupManager,
        bool $singleStep = false
    ): array {
        if (($journalState['live_mutation_started'] ?? false) === true
            && !$singleStep) {
            throw new UpdateApplyException(
                'Transaction already crossed the live mutation boundary; run --recover instead',
                'recovery_required'
            );
        }

        for ($guard = 0; $guard < 8; $guard++) {
            $journalState = $stateMachine->load($transactionId);
            $result = $this->applyTransition(
                $transactionId,
                $candidateOption,
                $journalState,
                $maintenance,
                $stateMachine,
                $applier,
                $backupManager
            );

            if ($singleStep || ($result['status'] ?? '') === 'committed') {
                return $result;
            }
        }

        throw new UpdateApplyException(
            'Updater exceeded the maximum number of transactional phases',
            'phase_guard_exceeded'
        );
    }

    /**
     * Выполняет ровно один долговечный переход updater-транзакции.
     *
     * @param array<string,mixed> $journalState
     * @return array<string,mixed>
     */
    private function applyTransition(
        string $transactionId,
        string $candidateOption,
        array $journalState,
        MaintenanceModeService $maintenance,
        UpdateTransactionStateMachine $stateMachine,
        UpdateLiveApplier $applier,
        UpdateBackupManager $backupManager
    ): array {
        $state = (string) ($journalState['state'] ?? '');

        if ($state === 'committed') {
            try {
                $maintenance->leave($transactionId);
            } catch (Throwable $e) {
                throw new UpdateApplyException(
                    'Update committed and verified, but maintenance could not be released: ' . $e->getMessage(),
                    'maintenance_release_failed',
                    1,
                    [
                        'transaction_state' => 'committed',
                        'maintenance_active' => true,
                    ]
                );
            }

            return [
                'status' => 'committed',
                'transaction_id' => $transactionId,
                'installed_version' => (string) ($journalState['target_version'] ?? ''),
                'installed_version_code' => (int) ($journalState['target_version_code'] ?? 0),
                'maintenance_active' => false,
                'ws_restarted' => (bool) ($journalState['postcheck']['ws_restarted'] ?? false),
            ];
        }

        if (!in_array($state, [
            'backup_verified',
            'candidate_verified',
            'preflight_verified',
            'live_mutation_started',
            'code_switched',
            'migrations_applied',
            'postcheck_verified',
        ], true)) {
            throw new UpdateApplyException(
                "Transaction state {$state} is not eligible for live apply",
                'invalid_transaction_state'
            );
        }

        [$journalState, $candidate, $verifiedBackup] = $this->verifiedApplyArtifacts(
            $transactionId,
            $candidateOption,
            $journalState,
            $stateMachine,
            $applier,
            $backupManager
        );
        $state = (string) ($journalState['state'] ?? '');

        if (in_array($state, ['backup_verified', 'candidate_verified'], true)) {
            $liveVersion = $applier->readLiveVersion();
            $this->assertVersion(
                $journalState,
                $liveVersion,
                false,
                'Live version changed since rollback checkpoint was created'
            );
            if ($liveVersion['version_code'] !== Version::VERSION_CODE) {
                throw new UpdateApplyException(
                    'Updater code no longer matches the live application version before switch',
                    'updater_version_mismatch'
                );
            }

            // До destructive-границы выполняются только проверки исходного
            // приложения, candidate и rollback backup.
            $health = $this->health($this->appRoot);
            $migrationPreflight = $this->migrationPreflight(
                $candidate['candidate_dir'],
                (int) ($journalState['installed_version_code'] ?? 0)
            );
            $candidate = $applier->verifyCandidateTree($candidate['candidate_dir']);
            $verifiedBackup = $backupManager->verify($verifiedBackup['backup_dir'], $transactionId);
            $ws = $this->wsStatus($this->appRoot);
            if ($ws['running'] && !function_exists('pcntl_fork')) {
                throw new UpdateApplyException(
                    'Запущенный WebSocket требует CLI pcntl для безопасного перезапуска; '
                    . 'остановите его через process manager или используйте Long Poll',
                    'ws_restart_unavailable'
                );
            }

            $stateMachine->markPreflightVerified($transactionId, [
                'health_status' => $health['status'] ?? null,
                'migration_preflight_sha256' => $migrationPreflight['sha256'],
                'migration_manifest_sha256' => $migrationPreflight['manifest_sha256'],
                'migration_ledger_present' => $migrationPreflight['ledger_present'],
                'migration_pending' => $migrationPreflight['pending'],
                'candidate_tree_sha256' => $candidate['tree_sha256'],
                'backup_manifest_sha256' => $verifiedBackup['manifest_sha256'],
                'ws_was_running' => $ws['running'],
                'verified_at' => time(),
            ]);
            $this->logService('updater.preflight_verified', 'info', $transactionId, [
                'installed_version' => (string) ($journalState['installed_version'] ?? ''),
                'target_version' => (string) ($journalState['target_version'] ?? ''),
                'migration_ledger_present' => (bool) $migrationPreflight['ledger_present'],
                'migration_pending' => (int) $migrationPreflight['pending'],
            ]);

            return $this->phaseResult(
                $transactionId,
                'preflight_verified',
                'switch',
                78,
                'Предварительные проверки пройдены. Переключается код.'
            );
        }

        $restartWs = (bool) ($journalState['preflight']['ws_was_running'] ?? false);

        if (in_array($state, ['preflight_verified', 'live_mutation_started'], true)) {
            if ($state === 'preflight_verified') {
                $liveVersion = $applier->readLiveVersion();
                $this->assertVersion($journalState, $liveVersion, false,
                    'Рабочая версия изменилась после предварительной проверки');
                // План фиксируется до изменения первого рабочего файла.
                $plan = $applier->prepareCodeSwitch($transactionId,
                    $candidate['candidate_dir'], $verifiedBackup['backup_dir']);
                $journalState = $stateMachine->markLiveMutationStarted($transactionId, [
                    'started_at' => time(), 'target_version' => $candidate['target_version'],
                    'target_version_code' => $candidate['target_version_code'], 'plan' => $plan,
                ]);
            } else {
                $plan = $journalState['apply']['plan'] ?? null;
                if (!is_array($plan)) throw new RuntimeException('Журнал не содержит план продолжения переключения');
            }
            try {
                $switch = $applier->switchPrepared($plan, $this->budget);
                $stateMachine->markCodeSwitched($transactionId, $switch);
                $this->logService('updater.code_switched', 'info', $transactionId, [
                    'target_version' => (string) ($journalState['target_version'] ?? ''),
                    'files_replaced' => count((array) ($switch['replaced_files'] ?? [])),
                    'files_deleted' => count((array) ($switch['deleted_files'] ?? [])),
                ]);
            } catch (UpdateStepPending $pause) {
                throw $pause;
            } catch (Throwable $applyError) {
                $this->rollbackAfterApplyError(
                    $transactionId,
                    $applyError,
                    $maintenance,
                    $stateMachine,
                    $applier,
                    $backupManager,
                    $restartWs,
                    'code_switch_failed'
                );
            }

            return $this->phaseResult(
                $transactionId,
                'code_switched',
                'migrations',
                84,
                'Код переключён. Выполняются миграции базы данных.',
                $this->runtimeRefreshDelayMs()
            );
        }

        if ($state === 'code_switched') {
            try {
                $postSwitchVersion = $applier->readLiveVersion();
                $this->assertVersion(
                    $journalState,
                    $postSwitchVersion,
                    true,
                    'Live version does not match signed target after code switch'
                );

                $migrate = $this->run(
                    [
                        PHP_BINARY,
                        $this->appRoot . '/bin/migrate.php',
                        '--baseline-version-code='
                            . (int) ($journalState['installed_version_code'] ?? 0),
                    ],
                    $this->appRoot,
                    300
                );
                if ($migrate['code'] !== 0) {
                    throw new RuntimeException(
                        'Live migration failed: ' . $this->commandFailureDetails($migrate)
                    );
                }

                $stateMachine->markMigrationsApplied($transactionId, [
                    'output_sha256' => hash('sha256', $migrate['stdout']),
                    'completed_at' => time(),
                ]);
                $this->logService('updater.migrations_applied', 'info', $transactionId, [
                    'source_version_code' => (int) ($journalState['installed_version_code'] ?? 0),
                    'target_version_code' => (int) ($journalState['target_version_code'] ?? 0),
                ]);
            } catch (UpdateStepPending $pause) {
                throw $pause;
            } catch (Throwable $applyError) {
                $this->rollbackAfterApplyError(
                    $transactionId,
                    $applyError,
                    $maintenance,
                    $stateMachine,
                    $applier,
                    $backupManager,
                    $restartWs,
                    'migration_failed'
                );
            }

            return $this->phaseResult(
                $transactionId,
                'migrations_applied',
                'postcheck',
                91,
                'Миграции применены. Проверяется работоспособность новой версии.'
            );
        }

        if ($state === 'migrations_applied') {
            try {
                $postHealth = $this->health($this->appRoot);
                $postVersion = $applier->readLiveVersion();
                $this->assertVersion(
                    $journalState,
                    $postVersion,
                    true,
                    'Post-update live version does not match signed target'
                );
                $migrationStatus = $this->migrationStatus($this->appRoot);
                $wsRestarted = false;
                $wsStoppedBecauseMessengerUnavailable = false;
                if ($restartWs) {
                    if ($this->messengerRuntimeEnabled($this->appRoot)) {
                        $this->restartWs($this->appRoot);
                        $wsRestarted = true;
                    } else {
                        $this->stopWs($this->appRoot);
                        $wsStoppedBecauseMessengerUnavailable = true;
                    }
                }

                $stateMachine->markPostcheckVerified($transactionId, [
                    'version' => $postVersion['version'],
                    'version_code' => $postVersion['version_code'],
                    'health_status' => $postHealth['status'] ?? null,
                    'migration_status_sha256' => hash('sha256', $migrationStatus['stdout']),
                    'ws_restarted' => $wsRestarted,
                    'ws_stopped_messenger_unavailable' => $wsStoppedBecauseMessengerUnavailable,
                    'verified_at' => time(),
                ]);
                $this->logService('updater.postcheck_verified', 'info', $transactionId, [
                    'version' => (string) $postVersion['version'],
                    'health_status' => (string) ($postHealth['status'] ?? ''),
                    'ws_restarted' => $wsRestarted,
                ]);
            } catch (UpdateStepPending $pause) {
                throw $pause;
            } catch (Throwable $applyError) {
                $this->rollbackAfterApplyError(
                    $transactionId,
                    $applyError,
                    $maintenance,
                    $stateMachine,
                    $applier,
                    $backupManager,
                    $restartWs,
                    'postcheck_failed'
                );
            }

            return $this->phaseResult(
                $transactionId,
                'postcheck_verified',
                'commit',
                97,
                'Новая версия прошла проверку. Завершается транзакция.'
            );
        }

        if ($state === 'postcheck_verified') {
            $post = is_array($journalState['postcheck'] ?? null)
                ? $journalState['postcheck']
                : [];
            $stateMachine->markCommitted($transactionId, [
                'committed_at' => time(),
                'version' => (string) ($post['version'] ?? $journalState['target_version'] ?? ''),
            ]);
            $this->logService('updater.committed', 'info', $transactionId, [
                'version' => (string) ($journalState['target_version'] ?? ''),
                'version_code' => (int) ($journalState['target_version_code'] ?? 0),
            ]);

            try {
                $maintenance->leave($transactionId);
            } catch (Throwable $e) {
                throw new UpdateApplyException(
                    'Update committed and verified, but maintenance could not be released: ' . $e->getMessage(),
                    'maintenance_release_failed',
                    1,
                    [
                        'transaction_state' => 'committed',
                        'maintenance_active' => true,
                    ]
                );
            }

            return [
                'status' => 'committed',
                'transaction_id' => $transactionId,
                'installed_version' => (string) ($journalState['target_version'] ?? ''),
                'installed_version_code' => (int) ($journalState['target_version_code'] ?? 0),
                'maintenance_active' => false,
                'ws_restarted' => (bool) ($post['ws_restarted'] ?? false),
            ];
        }

        throw new UpdateApplyException(
            "Transaction state {$state} is not supported by apply transition",
            'invalid_transaction_state'
        );
    }

    /**
     * @param array<string,mixed> $journalState
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>}
     */
    private function verifiedApplyArtifacts(
        string $transactionId,
        string $candidateOption,
        array $journalState,
        UpdateTransactionStateMachine $stateMachine,
        UpdateLiveApplier $applier,
        UpdateBackupManager $backupManager
    ): array {
        if ($candidateOption === '') {
            throw new UpdateApplyException(
                '--candidate-dir is required with --apply',
                'candidate_required',
                2
            );
        }

        $backups = $journalState['backups'] ?? null;
        if (!is_array($backups)
            || !isset($backups['backup_dir'], $backups['manifest_sha256'])) {
            throw new UpdateApplyException(
                'Transaction does not contain verified rollback backup metadata',
                'backup_required'
            );
        }

        $verifiedBackup = $backupManager->verify(
            (string) $backups['backup_dir'],
            $transactionId
        );
        if (!hash_equals(
            (string) $backups['manifest_sha256'],
            (string) $verifiedBackup['manifest_sha256']
        )) {
            throw new UpdateApplyException(
                'Rollback backup manifest changed after journal checkpoint',
                'backup_tampered'
            );
        }

        $candidate = $applier->verifyCandidateTree($candidateOption);
        if (!hash_equals(
            (string) ($journalState['target_version'] ?? ''),
            (string) $candidate['target_version']
        ) || (int) ($journalState['target_version_code'] ?? -1)
            !== (int) $candidate['target_version_code']) {
            throw new UpdateApplyException(
                'Release candidate target does not match updater transaction',
                'candidate_mismatch'
            );
        }

        $state = (string) ($journalState['state'] ?? '');
        if ($state === 'backup_verified') {
            $journalState = $stateMachine->recordCandidate($transactionId, [
                'candidate_dir' => $candidate['candidate_dir'],
                'tree_manifest' => $candidate['candidate_dir'] . '/.workspace-release-tree.json',
                'tree_sha256' => $candidate['tree_sha256'],
                'target_version' => $candidate['target_version'],
                'target_version_code' => $candidate['target_version_code'],
                'files' => $candidate['files'],
                'total_bytes' => $candidate['total_bytes'],
            ]);
        } else {
            $attached = $journalState['candidate'] ?? null;
            if (!is_array($attached)
                || !hash_equals(
                    (string) ($attached['candidate_dir'] ?? ''),
                    (string) $candidate['candidate_dir']
                )
                || !hash_equals(
                    (string) ($attached['tree_sha256'] ?? ''),
                    (string) $candidate['tree_sha256']
                )) {
                throw new UpdateApplyException(
                    'Transaction is already bound to a different release candidate',
                    'candidate_mismatch'
                );
            }
        }

        return [$journalState, $candidate, $verifiedBackup];
    }

    /** @return array<string,mixed> */
    private function phaseResult(
        string $transactionId,
        string $state,
        string $phase,
        int $progress,
        string $message,
        int $runtimeRefreshDelayMs = 0
    ): array {
        $result = [
            'status' => 'in_progress',
            'transaction_id' => $transactionId,
            'state' => $state,
            'phase' => $phase,
            'progress' => $progress,
            'message' => $message,
            'maintenance_active' => true,
        ];

        if ($runtimeRefreshDelayMs > 0) {
            $result['runtime_refresh_delay_ms'] = $runtimeRefreshDelayMs;
        }

        return $result;
    }

    private function runtimeRefreshDelayMs(): int
    {
        if (!extension_loaded('Zend OPcache')) {
            return 250;
        }

        $validate = ini_get('opcache.validate_timestamps');
        if ($validate !== false
            && in_array(strtolower(trim((string) $validate)), ['0', 'off', 'false', 'no'], true)) {
            return 5000;
        }

        $seconds = max(0, (int) ini_get('opcache.revalidate_freq'));
        return min(60000, max(500, ($seconds + 1) * 1000));
    }

    private function rollbackAfterApplyError(
        string $transactionId,
        Throwable $applyError,
        MaintenanceModeService $maintenance,
        UpdateTransactionStateMachine $stateMachine,
        UpdateLiveApplier $applier,
        UpdateBackupManager $backupManager,
        bool $restartWs,
        string $failureCode = 'apply_failed'
    ): never {
        $journalState = $stateMachine->load($transactionId);
        $applyErrorCode = preg_match('/^[a-z0-9_]{1,64}$/D', $failureCode) === 1
            ? $failureCode
            : ($applyError instanceof UpdateApplyException
                ? $applyError->errorCode
                : 'apply_failed');

        $this->logService('updater.apply_failed', 'error', $transactionId, [
            'state' => (string) ($journalState['state'] ?? ''),
            'error_type' => $applyError::class,
            'message' => $applyError->getMessage(),
            'failure_code' => $applyErrorCode,
            'source_version_code' => (int) ($journalState['installed_version_code'] ?? 0),
            'target_version_code' => (int) ($journalState['target_version_code'] ?? 0),
        ]);
        try {
            $verified = $this->rollback(
                $transactionId,
                $journalState,
                $stateMachine,
                $applier,
                $backupManager,
                $restartWs,
                $applyErrorCode
            );
        } catch (UpdateStepPending $pause) {
            throw $pause;
        } catch (Throwable $rollbackError) {
            $this->recordRollbackFailure($stateMachine, $transactionId, [
                'apply_error' => $applyError->getMessage(),
                'apply_error_code' => $applyErrorCode,
                'rollback_error' => $rollbackError->getMessage(),
                'at' => time(),
            ]);
            throw new UpdateApplyException(
                'Update failed and rollback did not verify; maintenance remains active. Apply error: '
                . $applyError->getMessage()
                . '; rollback error: '
                . $rollbackError->getMessage(),
                'rollback_failed',
                1,
                ['maintenance_active' => true]
            );
        }

        try {
            $maintenance->leave($transactionId);
        } catch (Throwable $e) {
            throw new UpdateApplyException(
                'Update failed, rollback is verified, but maintenance could not be released: '
                . $e->getMessage(),
                'maintenance_release_failed',
                1,
                [
                    'apply_error' => $applyError->getMessage(),
                    'transaction_state' => 'rollback_verified',
                    'rollback' => $verified,
                    'maintenance_active' => true,
                ]
            );
        }

        throw new UpdateApplyException(
            'Update failed after live mutation and was rolled back: '
            . $applyError->getMessage(),
            'apply_rolled_back',
            1,
            [
                'rollback' => $verified,
                'maintenance_active' => false,
                'apply_error_code' => $applyErrorCode,
            ]
        );
    }

    private function rollback(
        string $transactionId,
        array $journalState,
        UpdateTransactionStateMachine $stateMachine,
        UpdateLiveApplier $applier,
        UpdateBackupManager $backupManager,
        bool $restartWs,
        ?string $failureCode = null
    ): array {
        $artifacts = $this->recoveryArtifacts($journalState);
        $backups = $backupManager->verify($artifacts['backup_dir'], $transactionId);

        $current = $stateMachine->load($transactionId);
        $state = (string) ($current['state'] ?? '');
        if (in_array($state, [
            'live_mutation_started',
            'code_switched',
            'migrations_applied',
            'postcheck_verified',
            'rollback_failed',
        ], true)) {
            $rollbackStart = [
                'started_at' => time(),
                'from_state' => $state,
            ];
            if (is_string($failureCode)
                && preg_match('/^[a-z0-9_]{1,64}$/D', $failureCode) === 1) {
                $rollbackStart['failure_code'] = $failureCode;
            }

            $current = $stateMachine->markRollbackStarted(
                $transactionId,
                $rollbackStart
            );
            $state = 'rollback_started';
        }

        if ($state === 'rollback_started') {
            $code = (new UpdateRollbackCodeRestorer($this->appRoot))->restore(
                $transactionId,
                $backups['backup_dir'],
                $this->budget
            );
            $stateMachine->markCodeRestored($transactionId, $code);
            $state = 'code_restored';
        }

        if ($state === 'code_restored') {
            $db = $this->database();
            try {
                $database = $applier->restoreDatabase(
                    $db,
                    $backups['backup_dir'],
                    $backups['database'],
                    $this->budget
                );
            } finally {
                $db->close();
            }
            $stateMachine->markDatabaseRestored($transactionId, $database);
            $state = 'database_restored';
        }

        if ($state !== 'database_restored') {
            throw new RuntimeException("Rollback cannot resume from updater transaction state {$state}");
        }

        $restored = $applier->readLiveVersion();
        $this->assertVersion($journalState, $restored, false, 'Rollback restored an unexpected application version');
        $verification = $this->verifyRollbackState(
            $transactionId,
            $journalState,
            $stateMachine
        );
        if ($restartWs) {
            $this->restartWs($this->appRoot);
        }

        $verified = [
            'version' => $restored['version'],
            'version_code' => $restored['version_code'],
            'health_status' => $verification['health_status'],
            'migration_status_sha256' => $verification['migration_status_sha256'],
            'verification_mode' => $verification['verification_mode'],
            'ws_restarted' => $restartWs,
            'verified_at' => time(),
        ];
        $stateMachine->markRollbackVerified($transactionId, $verified);
        $this->logService('updater.rollback_verified', 'warning', $transactionId, [
            'version' => (string) $restored['version'],
            'version_code' => (int) $restored['version_code'],
            'health_status' => (string) $verification['health_status'],
            'verification_mode' => (string) $verification['verification_mode'],
        ]);
        return $verified;
    }

    /**
     * После пофайлового возврата старой версии web-only режим остаётся в том же
     * PHP-запросе. Загруженные классы целевой версии уже нельзя выгрузить, поэтому
     * запуск migrate/health из восстановленного дерева в этом процессе создаёт
     * смешанный runtime двух версий. Для web-only подтверждаем уже проверенный
     * снимок кода и БД; следующий HTTP-запрос стартует с чистым runtime.
     *
     * @param array<string,mixed> $journalState
     * @return array{health_status:string,migration_status_sha256:string,verification_mode:string}
     */
    private function verifyRollbackState(
        string $transactionId,
        array $journalState,
        UpdateTransactionStateMachine $stateMachine
    ): array {
        if ($this->processRunner::class !== __NAMESPACE__ . '\\UpdateInProcessRunner') {
            $health = $this->health($this->appRoot);
            $migrationStatus = $this->migrationStatus(
                $this->appRoot,
                (int) ($journalState['installed_version_code'] ?? 0)
            );

            return [
                'health_status' => (string) ($health['status'] ?? ''),
                'migration_status_sha256' => hash('sha256', $migrationStatus['stdout']),
                'verification_mode' => 'cold_process',
            ];
        }

        $current = $stateMachine->load($transactionId);
        $database = is_array($current['rollback_database'] ?? null)
            ? $current['rollback_database']
            : [];
        $preflight = is_array($current['preflight'] ?? null)
            ? $current['preflight']
            : [];

        $dumpSha = strtolower(trim((string) ($database['dump_sha256'] ?? '')));
        $tables = (int) ($database['tables'] ?? 0);
        $triggers = (int) ($database['triggers'] ?? -1);
        if (
            preg_match('/^[0-9a-f]{64}$/D', $dumpSha) !== 1
            || $tables < 1
            || $triggers < 0
            || (string) ($preflight['health_status'] ?? '') !== 'ok'
        ) {
            throw new RuntimeException(
                'Rollback web-only не содержит достаточного подтверждения проверенного снимка'
            );
        }

        $proof = json_encode(
            [
                'installed_version' => (string) ($journalState['installed_version'] ?? ''),
                'installed_version_code' => (int) ($journalState['installed_version_code'] ?? 0),
                'dump_sha256' => $dumpSha,
                'tables' => $tables,
                'triggers' => $triggers,
                'preflight_health_status' => 'ok',
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        return [
            'health_status' => 'rollback_snapshot_verified',
            'migration_status_sha256' => hash('sha256', $proof),
            'verification_mode' => 'web_snapshot',
        ];
    }

    /** @param array<string,mixed> $journalState @return array{backup_dir:string} */
    private function recoveryArtifacts(array $journalState): array
    {
        $backups = $journalState['backups'] ?? null;
        if (!is_array($backups) || !is_array($backups['database'] ?? null)) {
            throw new RuntimeException('Updater journal does not contain complete rollback backup metadata');
        }
        $backupDir = (string) ($backups['backup_dir'] ?? '');
        if ($backupDir === '') {
            throw new RuntimeException('Updater journal rollback backup path is missing');
        }
        return ['backup_dir' => $backupDir];
    }

    /** @param array<string,mixed> $journalState @param array{version:string,version_code:int} $actual */
    private function assertVersion(array $journalState, array $actual, bool $target, string $message): void
    {
        $versionKey = $target ? 'target_version' : 'installed_version';
        $codeKey = $target ? 'target_version_code' : 'installed_version_code';
        if (
            !hash_equals((string) ($journalState[$versionKey] ?? ''), $actual['version'])
            || (int) ($journalState[$codeKey] ?? -1) !== $actual['version_code']
        ) {
            throw new RuntimeException($message);
        }
    }

    /** @return array<string,mixed> */
    private function health(string $root): array
    {
        $result = $this->run([PHP_BINARY, $root . '/bin/healthcheck.php', '--json'], $root, 120);
        $payload = json_decode($result['stdout'], true);
        if ($result['code'] !== 0 || !is_array($payload) || ($payload['status'] ?? null) !== 'ok') {
            throw new RuntimeException('Updater healthcheck failed: ' . $this->commandFailureDetails($result));
        }
        return $payload;
    }

    /**
     * Validate target migration data without executing candidate PHP.
     *
     * @return array{sha256:string,manifest_sha256:string,ledger_present:bool,pending:int}
     */
    private function migrationPreflight(
        string $candidateRoot,
        int $baselineVersionCode
    ): array {
        $db = $this->database();
        try {
            $result = (new UpdateMigrationPreflight($candidateRoot))->check(
                $db,
                $baselineVersionCode
            );
        } finally {
            $db->close();
        }

        $bytes = json_encode(
            $result,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        return [
            'sha256' => hash('sha256', $bytes),
            'manifest_sha256' => (string) $result['manifest_sha256'],
            'ledger_present' => (bool) $result['ledger_present'],
            'pending' => (int) $result['pending'],
        ];
    }

    /** @return array{code:int,stdout:string,stderr:string} */
    private function migrationStatus(string $root, ?int $baselineVersionCode = null): array
    {
        $command = [PHP_BINARY, $root . '/bin/migrate.php', '--status'];
        if (is_int($baselineVersionCode) && $baselineVersionCode > 0) {
            $command[] = '--baseline-version-code=' . $baselineVersionCode;
        }

        $result = $this->run($command, $root, 180);
        if ($result['code'] !== 0) {
            throw new RuntimeException(
                'Migration checksum/schema status failed: ' . $this->commandFailureDetails($result)
            );
        }
        return $result;
    }

    /** @return array{running:bool,output:string} */
    private function wsStatus(string $root): array
    {
        $result = $this->run([PHP_BINARY, $root . '/ws_server/server.php', 'status'], $root, 20);
        if ($result['code'] === 0) {
            return ['running' => true, 'output' => trim($result['stdout'])];
        }

        // Команда status имеет машинный контракт по коду возврата:
        // 0 — процесс запущен, 1 — процесс не запущен. Текст локализован
        // и не должен влиять на логику обновлятора.
        if ($result['code'] === 1) {
            return ['running' => false, 'output' => trim($result['stdout'])];
        }

        throw new RuntimeException('Не удалось определить состояние WebSocket-процесса: ' . $this->commandFailureDetails($result));
    }

    private function messengerRuntimeEnabled(string $root): bool
    {
        $probe = <<<'PHP'
$root = (string) ($argv[1] ?? '');
if ($root === '' || !is_dir($root)) {
    exit(2);
}
if (!defined('SITEPATH')) {
    define('SITEPATH', $root);
}
require $root . '/core.php';
$runtime = \Core\ModuleRuntimeLoader::getInstance();
exit(isset($runtime->providers()['messenger']) ? 0 : 3);
PHP;

        $result = $this->run([PHP_BINARY, '-r', $probe, $root], $root, 30);
        if ($result['code'] === 0) {
            return true;
        }
        if ($result['code'] === 3) {
            return false;
        }

        throw new RuntimeException(
            'Не удалось определить доступность Messenger после обновления: '
            . $this->commandFailureDetails($result)
        );
    }

    private function stopWs(string $root): void
    {
        $result = $this->run([PHP_BINARY, $root . '/ws_server/server.php', 'stop'], $root, 20);
        if ($result['code'] !== 0) {
            throw new RuntimeException(
                'Не удалось остановить WebSocket после отключения Messenger: '
                . $this->commandFailureDetails($result)
            );
        }
    }

    private function restartWs(string $root): void
    {
        $result = $this->run([PHP_BINARY, $root . '/ws_server/server.php', 'restart', '-d'], $root, 30);
        if ($result['code'] !== 0) {
            throw new RuntimeException('WebSocket restart failed: ' . $this->commandFailureDetails($result));
        }
        $status = $this->wsStatus($root);
        if (!$status['running']) {
            throw new RuntimeException('WebSocket server did not become healthy after restart');
        }
    }

    /** @return array{code:int,stdout:string,stderr:string} */
    private function run(array $command, string $cwd, int $timeoutSeconds): array
    {
        return $this->processRunner->run($command, $cwd, $timeoutSeconds);
    }

    /** @param array{code:int,stdout:string,stderr:string} $result */
    private function commandFailureDetails(array $result): string
    {
        return $this->processRunner->failureDetails($result);
    }

    /** @return list<string> */
    private function mutablePaths(): array
    {
        $paths = [];
        foreach ([
            'PRIVATE_STORAGE_PATH',
            'UPLOAD_DIR',
            'NOTES_UPLOAD_DIR',
            'MESSENGER_UPLOAD_DIR',
            'RATE_LIMIT_STORAGE_PATH',
            'UPDATE_STAGING_PATH',
            'UPDATE_STATE_PATH',
            'UPDATE_BACKUP_PATH',
            'UPDATE_RELEASE_PATH',
            'WS_PID_FILE',
            'LOG_FILE',
        ] as $name) {
            $value = getenv($name);
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            if (in_array($name, ['WS_PID_FILE', 'LOG_FILE'], true)) {
                $value = dirname(trim($value));
            }
            $paths[] = trim($value);
        }
        return array_values(array_unique($paths));
    }

    private function resolveRoot(string $explicit, string $envName, string $privateSuffix): string
    {
        $explicit = trim($explicit);
        if ($explicit !== '') {
            return rtrim($explicit, '/\\');
        }
        $configured = getenv($envName);
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/\\');
        }
        $private = getenv('PRIVATE_STORAGE_PATH');
        if (is_string($private) && trim($private) !== '') {
            return rtrim(trim($private), '/\\') . DIRECTORY_SEPARATOR . $privateSuffix;
        }
        throw new UpdateApplyException(
            "{$envName} is not configured and PRIVATE_STORAGE_PATH is unavailable",
            'path_not_configured',
            2
        );
    }

    private function database(): mysqli
    {
        if (!extension_loaded('mysqli')) {
            throw new RuntimeException('PHP mysqli extension is required for updater database operations');
        }
        $user = getenv('DBUSER');
        $name = getenv('DBNAME');
        if (!is_string($user) || trim($user) === '' || !is_string($name) || trim($name) === '') {
            throw new RuntimeException('Database environment is incomplete');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new mysqli(
            (string) (getenv('DBHOST') ?: 'localhost'),
            trim($user),
            (string) (getenv('DBPASS') ?: ''),
            trim($name),
            (int) (getenv('DBPORT') ?: 3306)
        );
        $db->set_charset('utf8mb4');
        return $db;
    }

    /** @param array<string,mixed> $details */
    private function logService(
        string $event,
        string $level,
        string $transactionId,
        array $details = []
    ): void {
        $details['transaction_id'] = $transactionId;
        ServiceLog::emit($event, $level, 'updater', $details);
    }

    /** @param array<string,mixed> $details */
    private function recordRollbackFailure(
        UpdateTransactionStateMachine $stateMachine,
        string $transactionId,
        array $details
    ): void {
        try {
            $stateMachine->markRollbackFailed($transactionId, $details);
        } catch (Throwable) {
            // Preserve the original recovery exception; maintenance remains active
            // even if journal failure prevents recording a richer terminal state.
        }
    }
}

final class UpdateApplyException extends RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'apply_failed',
        public readonly int $exitCode = 1,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}
