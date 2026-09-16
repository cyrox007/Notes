<?php

declare(strict_types=1);

namespace Core;

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

    public function __construct(string $appRoot, bool $json = false)
    {
        $real = realpath($appRoot);
        if (!is_string($real) || !is_dir($real) || is_link($appRoot)) {
            throw new RuntimeException('Application root cannot be resolved safely for updater apply');
        }
        $this->appRoot = rtrim($real, '/\\');
        $this->json = $json;
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
            return $this->recover(
                $transactionId,
                $journalState,
                $maintenance,
                $stateMachine,
                $applier,
                $backupManager
            );
        }

        if ($candidateOption === '') {
            throw new UpdateApplyException('--candidate-dir is required with --apply', 'candidate_required', 2);
        }

        return $this->apply(
            $transactionId,
            $candidateOption,
            $journalState,
            $maintenance,
            $stateMachine,
            $applier,
            $backupManager
        );
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

        if (!$liveMutation && in_array($state, ['backup_verified', 'candidate_verified', 'preflight_verified'], true)) {
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
            $migrationStatus = $this->migrationStatus($this->appRoot);
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
            $migrationStatus = $this->migrationStatus($this->appRoot);
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
        } catch (Throwable $rollbackError) {
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
        UpdateBackupManager $backupManager
    ): array {
        $state = (string) ($journalState['state'] ?? '');
        if (($journalState['live_mutation_started'] ?? false) === true) {
            throw new UpdateApplyException(
                'Transaction already crossed the live mutation boundary; run --recover instead',
                'recovery_required'
            );
        }
        if (!in_array($state, ['backup_verified', 'candidate_verified'], true)) {
            throw new UpdateApplyException(
                "Transaction state {$state} is not eligible for a new live apply",
                'invalid_transaction_state'
            );
        }

        $backups = $journalState['backups'] ?? null;
        if (!is_array($backups) || !isset($backups['backup_dir'], $backups['manifest_sha256'])) {
            throw new UpdateApplyException('Transaction does not contain verified rollback backup metadata', 'backup_required');
        }

        $verifiedBackup = $backupManager->verify((string) $backups['backup_dir'], $transactionId);
        if (!hash_equals((string) $backups['manifest_sha256'], $verifiedBackup['manifest_sha256'])) {
            throw new UpdateApplyException('Rollback backup manifest changed after journal checkpoint', 'backup_tampered');
        }

        $candidate = $applier->verifyCandidateTree($candidateOption);
        if (
            !hash_equals((string) ($journalState['target_version'] ?? ''), $candidate['target_version'])
            || (int) ($journalState['target_version_code'] ?? -1) !== $candidate['target_version_code']
        ) {
            throw new UpdateApplyException('Release candidate target does not match updater transaction', 'candidate_mismatch');
        }

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
            if (
                !is_array($attached)
                || !hash_equals((string) ($attached['candidate_dir'] ?? ''), $candidate['candidate_dir'])
                || !hash_equals((string) ($attached['tree_sha256'] ?? ''), $candidate['tree_sha256'])
            ) {
                throw new UpdateApplyException(
                    'Transaction is already bound to a different release candidate',
                    'candidate_mismatch'
                );
            }
        }

        $liveVersion = $applier->readLiveVersion();
        $this->assertVersion($journalState, $liveVersion, false, 'Live version changed since rollback checkpoint was created');
        if ($liveVersion['version_code'] !== Version::VERSION_CODE) {
            throw new UpdateApplyException(
                'Updater process code no longer matches the live application version',
                'updater_version_mismatch'
            );
        }

        // All checks below are non-destructive.
        $health = $this->health($this->appRoot);
        $dryRun = $this->migrationDryRun($candidate['candidate_dir']);
        $candidate = $applier->verifyCandidateTree($candidate['candidate_dir']);
        $verifiedBackup = $backupManager->verify($verifiedBackup['backup_dir'], $transactionId);
        $ws = $this->wsStatus($this->appRoot);
        if ($ws['running'] && !function_exists('pcntl_fork')) {
            throw new UpdateApplyException(
                'Running WebSocket server requires CLI pcntl so updater can restart it after switch; stop it through the process manager or enable pcntl before apply',
                'ws_restart_unavailable'
            );
        }

        $journalState = $stateMachine->markPreflightVerified($transactionId, [
            'health_status' => $health['status'] ?? null,
            'migration_dry_run_sha256' => $dryRun['sha256'],
            'candidate_tree_sha256' => $candidate['tree_sha256'],
            'backup_manifest_sha256' => $verifiedBackup['manifest_sha256'],
            'ws_was_running' => $ws['running'],
            'verified_at' => time(),
        ]);

        // Deliberately mark the destructive boundary before preparing the sibling
        // switch directory. From here, a hard process crash is resolved only by
        // --recover from the verified checkpoint.
        $stateMachine->markLiveMutationStarted($transactionId, [
            'started_at' => time(),
            'target_version' => $candidate['target_version'],
            'target_version_code' => $candidate['target_version_code'],
        ]);

        try {
            $plan = $applier->prepareCodeSwitch(
                $transactionId,
                $candidate['candidate_dir'],
                $verifiedBackup['backup_dir']
            );
            $switch = $applier->switchPrepared($plan);
            $stateMachine->markCodeSwitched($transactionId, $switch);

            $migrate = $this->run([PHP_BINARY, $this->appRoot . '/bin/migrate.php'], $this->appRoot, 300);
            if ($migrate['code'] !== 0) {
                throw new RuntimeException(
                    'Live migration failed: ' . $this->commandFailureDetails($migrate)
                );
            }
            $stateMachine->markMigrationsApplied($transactionId, [
                'output_sha256' => hash('sha256', $migrate['stdout']),
                'completed_at' => time(),
            ]);

            $postHealth = $this->health($this->appRoot);
            $postVersion = $applier->readLiveVersion();
            $this->assertVersion($journalState, $postVersion, true, 'Post-update live version does not match signed target');
            $migrationStatus = $this->migrationStatus($this->appRoot);
            if ($ws['running']) {
                $this->restartWs($this->appRoot);
            }

            $post = [
                'version' => $postVersion['version'],
                'version_code' => $postVersion['version_code'],
                'health_status' => $postHealth['status'] ?? null,
                'migration_status_sha256' => hash('sha256', $migrationStatus['stdout']),
                'ws_restarted' => $ws['running'],
                'verified_at' => time(),
            ];
            $stateMachine->markPostcheckVerified($transactionId, $post);
            $stateMachine->markCommitted($transactionId, [
                'committed_at' => time(),
                'version' => $postVersion['version'],
            ]);
        } catch (Throwable $applyError) {
            $journalState = $stateMachine->load($transactionId);
            try {
                $verified = $this->rollback(
                    $transactionId,
                    $journalState,
                    $stateMachine,
                    $applier,
                    $backupManager,
                    $ws['running']
                );
            } catch (Throwable $rollbackError) {
                $this->recordRollbackFailure($stateMachine, $transactionId, [
                    'apply_error' => $applyError->getMessage(),
                    'rollback_error' => $rollbackError->getMessage(),
                    'at' => time(),
                ]);
                throw new UpdateApplyException(
                    'Update failed and rollback did not verify; maintenance remains active. Apply error: '
                    . $applyError->getMessage() . '; rollback error: ' . $rollbackError->getMessage(),
                    'rollback_failed',
                    1,
                    ['maintenance_active' => true]
                );
            }

            try {
                $maintenance->leave($transactionId);
            } catch (Throwable $e) {
                throw new UpdateApplyException(
                    'Update failed, rollback is verified, but maintenance could not be released: ' . $e->getMessage(),
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
                'Update failed after live mutation and was rolled back: ' . $applyError->getMessage(),
                'apply_rolled_back',
                1,
                ['rollback' => $verified, 'maintenance_active' => false]
            );
        }

        // Committed is terminal. Failure to remove the maintenance marker must
        // never cause a rollback of a verified committed update.
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
            'ws_restarted' => $ws['running'],
        ];
    }

    /**
     * Resume-safe rollback. Repeated work is intentional when a crash occurred
     * before a phase marker was durably written.
     *
     * The verified backup is the authoritative recovery artifact. The release
     * candidate may already have been deleted or damaged and is never required
     * to restore code/database state after the destructive boundary.
     *
     * @param array<string,mixed> $journalState
     * @return array<string,mixed>
     */
    private function rollback(
        string $transactionId,
        array $journalState,
        UpdateTransactionStateMachine $stateMachine,
        UpdateLiveApplier $applier,
        UpdateBackupManager $backupManager,
        bool $restartWs
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
            $current = $stateMachine->markRollbackStarted($transactionId, [
                'started_at' => time(),
                'from_state' => $state,
            ]);
            $state = 'rollback_started';
        }

        if ($state === 'rollback_started') {
            $code = (new UpdateRollbackCodeRestorer($this->appRoot))->restore(
                $transactionId,
                $backups['backup_dir']
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
                    $backups['database']
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
        $health = $this->health($this->appRoot);
        $migrationStatus = $this->migrationStatus($this->appRoot);
        if ($restartWs) {
            $this->restartWs($this->appRoot);
        }

        $verified = [
            'version' => $restored['version'],
            'version_code' => $restored['version_code'],
            'health_status' => $health['status'] ?? null,
            'migration_status_sha256' => hash('sha256', $migrationStatus['stdout']),
            'ws_restarted' => $restartWs,
            'verified_at' => time(),
        ];
        $stateMachine->markRollbackVerified($transactionId, $verified);
        return $verified;
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

    /** @return array{sha256:string,output:string} */
    private function migrationDryRun(string $candidateRoot): array
    {
        $result = $this->run([PHP_BINARY, $candidateRoot . '/bin/migrate.php', '--dry-run'], $candidateRoot, 180);
        if ($result['code'] !== 0) {
            throw new RuntimeException(
                'Candidate migration dry-run/checksum validation failed: ' . $this->commandFailureDetails($result)
            );
        }
        return ['sha256' => hash('sha256', $result['stdout']), 'output' => trim($result['stdout'])];
    }

    /** @return array{code:int,stdout:string,stderr:string} */
    private function migrationStatus(string $root): array
    {
        $result = $this->run([PHP_BINARY, $root . '/bin/migrate.php', '--status'], $root, 180);
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
        if ($result['code'] === 1 && str_contains(strtolower($result['stdout']), 'not running')) {
            return ['running' => false, 'output' => trim($result['stdout'])];
        }
        throw new RuntimeException('Cannot determine WebSocket process state: ' . $this->commandFailureDetails($result));
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
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start updater command');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;
        $exit = -1;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                $exit = (int) ($status['exitcode'] ?? -1);
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process);
                usleep(200_000);
                $status = proc_get_status($process);
                if ($status['running'] ?? false) {
                    proc_terminate($process, 9);
                }
                $exit = 124;
                break;
            }
            usleep(50_000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);
        if (!$timedOut && $exit < 0 && is_int($closeCode)) {
            $exit = $closeCode;
        }
        return ['code' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param array{code:int,stdout:string,stderr:string} $result */
    private function commandFailureDetails(array $result): string
    {
        $details = trim($result['stderr']) !== '' ? trim($result['stderr']) : trim($result['stdout']);
        return $details !== '' ? $details : 'exit code ' . $result['code'];
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
            throw new RuntimeException('PHP mysqli extension is required for updater rollback');
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
