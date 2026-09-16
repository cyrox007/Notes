<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/core/Environment.php';
if (is_file($root . '/.env')) {
    \Core\Environment::load($root . '/.env');
}
require_once $root . '/core/Version.php';
require_once $root . '/core/UpdateTransactionJournal.php';
require_once $root . '/core/UpdateTransactionStateMachine.php';
require_once $root . '/core/UpdateBackupManager.php';
require_once $root . '/core/UpdateLiveApplier.php';
require_once $root . '/app/services/MaintenanceModeService.php';

use App\Services\MaintenanceModeService;
use Core\UpdateBackupManager;
use Core\UpdateLiveApplier;
use Core\UpdateTransactionStateMachine;
use Core\Version;

$options = getopt('', [
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
    echo "Workspace Organizer transactional live updater\n\n";
    echo "Apply a previously verified candidate:\n";
    echo "  php bin/update_apply.php --transaction=update-... --candidate-dir=/external/releases/candidate-... --apply\n\n";
    echo "Recover an interrupted/failed live mutation from its verified rollback checkpoint:\n";
    echo "  php bin/update_apply.php --transaction=update-... --recover\n\n";
    echo "Optional: --state-root=/external/state --backup-root=/external/backups --json\n";
    echo "Maintenance must already be active and owned by the transaction.\n";
    exit(0);
}

$json = isset($options['json']);
$applyRequested = isset($options['apply']);
$recoverRequested = isset($options['recover']);
if ($applyRequested === $recoverRequested) {
    fwrite(STDERR, "Exactly one of --apply or --recover is required.\n");
    exit(2);
}

/** @return never */
function updateApplyFail(string $message, string $code = 'apply_failed', int $exitCode = 1, array $extra = []): never
{
    global $json;
    if ($json) {
        echo json_encode(['status' => 'fail', 'code' => $code, 'message' => $message] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, "[FAIL] {$message}\n");
    }
    exit($exitCode);
}

function updateApplyResolveRoot(string $explicit, string $envName, string $privateSuffix): string
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
    updateApplyFail("{$envName} is not configured and PRIVATE_STORAGE_PATH is unavailable", 'path_not_configured', 2);
}

/** @return list<string> */
function updateApplyMutablePaths(): array
{
    $paths = [];
    foreach ([
        'PRIVATE_STORAGE_PATH', 'UPLOAD_DIR', 'NOTES_UPLOAD_DIR', 'MESSENGER_UPLOAD_DIR',
        'RATE_LIMIT_STORAGE_PATH', 'UPDATE_STAGING_PATH', 'UPDATE_STATE_PATH',
        'UPDATE_BACKUP_PATH', 'UPDATE_RELEASE_PATH', 'WS_PID_FILE', 'LOG_FILE',
    ] as $name) {
        $value = getenv($name);
        if (!is_string($value) || trim($value) === '') {
            continue;
        }
        // File-valued settings protect their parent directory, not the file name.
        if (in_array($name, ['WS_PID_FILE', 'LOG_FILE'], true)) {
            $value = dirname(trim($value));
        }
        $paths[] = trim($value);
    }
    return array_values(array_unique($paths));
}

function updateApplyDb(): mysqli
{
    if (!extension_loaded('mysqli')) {
        updateApplyFail('PHP mysqli extension is required for updater rollback', 'mysqli_missing', 2);
    }
    $user = getenv('DBUSER');
    $name = getenv('DBNAME');
    if (!is_string($user) || trim($user) === '' || !is_string($name) || trim($name) === '') {
        updateApplyFail('Database environment is incomplete', 'database_config_missing', 2);
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

/** @return array{code:int,stdout:string,stderr:string} */
function updateApplyRun(array $command, string $cwd, int $timeoutSeconds = 300): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($command, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start updater validation command');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeoutSeconds;
    $timedOut = false;

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

/** @return array<string,mixed> */
function updateApplyHealth(string $applicationRoot): array
{
    $result = updateApplyRun([PHP_BINARY, $applicationRoot . '/bin/healthcheck.php', '--json'], $applicationRoot, 120);
    $payload = json_decode($result['stdout'], true);
    if ($result['code'] !== 0 || !is_array($payload) || ($payload['status'] ?? null) !== 'ok') {
        $reason = trim($result['stderr']) !== '' ? trim($result['stderr']) : trim($result['stdout']);
        throw new RuntimeException('Updater healthcheck failed' . ($reason !== '' ? ': ' . $reason : ''));
    }
    return $payload;
}

/** @return array<string,mixed> */
function updateApplyMigrationDryRun(string $candidateRoot): array
{
    $result = updateApplyRun([PHP_BINARY, $candidateRoot . '/bin/migrate.php', '--dry-run'], $candidateRoot, 180);
    if ($result['code'] !== 0) {
        $reason = trim($result['stderr']) !== '' ? trim($result['stderr']) : trim($result['stdout']);
        throw new RuntimeException('Candidate migration dry-run/checksum validation failed' . ($reason !== '' ? ': ' . $reason : ''));
    }
    return ['sha256' => hash('sha256', $result['stdout']), 'output' => trim($result['stdout'])];
}

/** @return array{running:bool,output:string} */
function updateApplyWsStatus(string $applicationRoot): array
{
    $result = updateApplyRun([PHP_BINARY, $applicationRoot . '/ws_server/server.php', 'status'], $applicationRoot, 20);
    if ($result['code'] === 0) {
        return ['running' => true, 'output' => trim($result['stdout'])];
    }
    if ($result['code'] === 1 && str_contains(strtolower($result['stdout']), 'not running')) {
        return ['running' => false, 'output' => trim($result['stdout'])];
    }
    throw new RuntimeException('Cannot determine WebSocket process state: ' . trim($result['stderr'] . ' ' . $result['stdout']));
}

function updateApplyRestartWs(string $applicationRoot): void
{
    $result = updateApplyRun([PHP_BINARY, $applicationRoot . '/ws_server/server.php', 'restart', '-d'], $applicationRoot, 30);
    if ($result['code'] !== 0) {
        throw new RuntimeException('WebSocket restart failed: ' . trim($result['stderr'] . ' ' . $result['stdout']));
    }
    $status = updateApplyWsStatus($applicationRoot);
    if (!$status['running']) {
        throw new RuntimeException('WebSocket server did not become healthy after restart');
    }
}

/** @param array<string,mixed> $journalState @return array{candidate_dir:string,backup_dir:string,database:array<string,mixed>} */
function updateApplyRecoveryArtifacts(array $journalState, string $candidateOverride = ''): array
{
    $candidate = $journalState['candidate'] ?? null;
    $backups = $journalState['backups'] ?? null;
    if (!is_array($candidate) || !is_array($backups) || !is_array($backups['database'] ?? null)) {
        throw new RuntimeException('Updater journal does not contain complete recovery artifacts');
    }
    $candidateDir = trim($candidateOverride) !== '' ? trim($candidateOverride) : (string) ($candidate['candidate_dir'] ?? '');
    $backupDir = (string) ($backups['backup_dir'] ?? '');
    if ($candidateDir === '' || $backupDir === '') {
        throw new RuntimeException('Updater journal recovery artifact paths are missing');
    }
    return ['candidate_dir' => $candidateDir, 'backup_dir' => $backupDir, 'database' => $backups['database']];
}

/**
 * @param array<string,mixed> $journalState
 * @return array<string,mixed>
 */
function updateApplyRollback(
    string $transactionId,
    array $journalState,
    UpdateTransactionStateMachine $stateMachine,
    UpdateLiveApplier $applier,
    UpdateBackupManager $backupManager,
    string $applicationRoot,
    bool $restartWs
): array {
    $artifacts = updateApplyRecoveryArtifacts($journalState);
    $backups = $backupManager->verify($artifacts['backup_dir'], $transactionId);
    $candidate = $applier->verifyCandidateTree($artifacts['candidate_dir']);

    $current = $stateMachine->load($transactionId);
    if (($current['state'] ?? null) !== 'rollback_started') {
        $current = $stateMachine->markRollbackStarted($transactionId, [
            'started_at' => time(),
            'from_state' => (string) ($journalState['state'] ?? ''),
        ]);
    }

    $code = $applier->restoreCode($transactionId, $backups['backup_dir'], $candidate['candidate_dir']);
    $stateMachine->markCodeRestored($transactionId, $code);

    $db = updateApplyDb();
    try {
        $database = $applier->restoreDatabase($db, $backups['backup_dir'], $backups['database']);
    } finally {
        $db->close();
    }
    $stateMachine->markDatabaseRestored($transactionId, $database);

    $restored = $applier->readLiveVersion();
    if (
        !hash_equals((string) ($journalState['installed_version'] ?? ''), $restored['version'])
        || (int) ($journalState['installed_version_code'] ?? -1) !== $restored['version_code']
    ) {
        throw new RuntimeException('Rollback restored an unexpected application version');
    }
    $health = updateApplyHealth($applicationRoot);
    $migrationStatus = updateApplyRun([PHP_BINARY, $applicationRoot . '/bin/migrate.php', '--status'], $applicationRoot, 180);
    if ($migrationStatus['code'] !== 0) {
        throw new RuntimeException('Rollback migration checksum/schema status failed: ' . trim($migrationStatus['stderr'] . ' ' . $migrationStatus['stdout']));
    }
    if ($restartWs) {
        updateApplyRestartWs($applicationRoot);
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

$transactionId = trim((string) ($options['transaction'] ?? ''));
if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1) {
    updateApplyFail('A valid --transaction id is required', 'invalid_transaction', 2);
}
$stateRoot = updateApplyResolveRoot((string) ($options['state-root'] ?? ''), 'UPDATE_STATE_PATH', 'updates');
$backupRoot = updateApplyResolveRoot((string) ($options['backup-root'] ?? ''), 'UPDATE_BACKUP_PATH', 'update-backups');
$candidateOption = trim((string) ($options['candidate-dir'] ?? ''));

try {
    $maintenance = new MaintenanceModeService($stateRoot, $root);
    $maintenanceState = $maintenance->state();
    if (!$maintenanceState['active'] || !$maintenanceState['valid']) {
        updateApplyFail('Transactional updater requires active valid maintenance', 'maintenance_required');
    }
    if (!hash_equals($transactionId, (string) $maintenanceState['transaction_id'])) {
        updateApplyFail('Maintenance mode belongs to another updater transaction', 'maintenance_owner_mismatch');
    }

    $stateMachine = new UpdateTransactionStateMachine($stateRoot, $root);
    $journalState = $stateMachine->load($transactionId);
    $applier = new UpdateLiveApplier($root);
    $mutablePaths = updateApplyMutablePaths();
    $applier->assertMutablePathsSwitchSafe($mutablePaths);
    $backupManager = new UpdateBackupManager($backupRoot, $root, $mutablePaths);

    if ($recoverRequested) {
        $state = (string) ($journalState['state'] ?? '');
        $liveMutation = ($journalState['live_mutation_started'] ?? false) === true;

        if (!$liveMutation && in_array($state, ['backup_verified', 'candidate_verified', 'preflight_verified'], true)) {
            $maintenance->leave($transactionId);
            $result = ['status' => 'recovered_without_live_mutation', 'transaction_id' => $transactionId, 'state' => $state, 'maintenance_active' => false];
            echo $json ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL : "[OK] No live mutation had started; maintenance released.\n";
            exit(0);
        }

        if ($state === 'committed') {
            $version = $applier->readLiveVersion();
            if (
                !hash_equals((string) ($journalState['target_version'] ?? ''), $version['version'])
                || (int) ($journalState['target_version_code'] ?? -1) !== $version['version_code']
            ) {
                updateApplyFail('Committed transaction does not match live target version; maintenance retained', 'committed_state_mismatch');
            }
            updateApplyHealth($root);
            $maintenance->leave($transactionId);
            $result = ['status' => 'committed_recovery_verified', 'transaction_id' => $transactionId, 'maintenance_active' => false];
            echo $json ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL : "[OK] Committed update verified; maintenance released.\n";
            exit(0);
        }

        if ($state === 'rollback_verified') {
            $version = $applier->readLiveVersion();
            if (
                !hash_equals((string) ($journalState['installed_version'] ?? ''), $version['version'])
                || (int) ($journalState['installed_version_code'] ?? -1) !== $version['version_code']
            ) {
                updateApplyFail('Verified rollback no longer matches installed version; maintenance retained', 'rollback_state_mismatch');
            }
            updateApplyHealth($root);
            $maintenance->leave($transactionId);
            $result = ['status' => 'rollback_recovery_verified', 'transaction_id' => $transactionId, 'maintenance_active' => false];
            echo $json ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL : "[OK] Verified rollback confirmed; maintenance released.\n";
            exit(0);
        }

        try {
            $restartWs = (bool) (($journalState['preflight']['ws_was_running'] ?? false));
            $verified = updateApplyRollback($transactionId, $journalState, $stateMachine, $applier, $backupManager, $root, $restartWs);
            $maintenance->leave($transactionId);
            $result = ['status' => 'rolled_back', 'transaction_id' => $transactionId, 'rollback' => $verified, 'maintenance_active' => false];
            echo $json ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL : "[OK] Interrupted update rolled back and verified.\n";
            exit(0);
        } catch (Throwable $rollbackError) {
            try {
                $stateMachine->markRollbackFailed($transactionId, ['message' => $rollbackError->getMessage(), 'at' => time()]);
            } catch (Throwable) {
            }
            updateApplyFail('Rollback failed; maintenance remains active: ' . $rollbackError->getMessage(), 'rollback_failed', 1, ['maintenance_active' => true]);
        }
    }

    if ($candidateOption === '') {
        updateApplyFail('--candidate-dir is required with --apply', 'candidate_required', 2);
    }
    $state = (string) ($journalState['state'] ?? '');
    if (($journalState['live_mutation_started'] ?? false) === true) {
        updateApplyFail('Transaction already crossed the live mutation boundary; run --recover instead', 'recovery_required');
    }
    if (!in_array($state, ['backup_verified', 'candidate_verified'], true)) {
        updateApplyFail("Transaction state {$state} is not eligible for a new live apply", 'invalid_transaction_state');
    }

    $backups = $journalState['backups'] ?? null;
    if (!is_array($backups) || !isset($backups['backup_dir'], $backups['manifest_sha256'])) {
        updateApplyFail('Transaction does not contain verified rollback backup metadata', 'backup_required');
    }
    $verifiedBackup = $backupManager->verify((string) $backups['backup_dir'], $transactionId);
    if (!hash_equals((string) $backups['manifest_sha256'], $verifiedBackup['manifest_sha256'])) {
        updateApplyFail('Rollback backup manifest changed after journal checkpoint', 'backup_tampered');
    }

    $candidate = $applier->verifyCandidateTree($candidateOption);
    if (
        !hash_equals((string) ($journalState['target_version'] ?? ''), $candidate['target_version'])
        || (int) ($journalState['target_version_code'] ?? -1) !== $candidate['target_version_code']
    ) {
        updateApplyFail('Release candidate target does not match updater transaction', 'candidate_mismatch');
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
        if (!is_array($attached)
            || !hash_equals((string) ($attached['candidate_dir'] ?? ''), $candidate['candidate_dir'])
            || !hash_equals((string) ($attached['tree_sha256'] ?? ''), $candidate['tree_sha256'])) {
            updateApplyFail('Transaction is already bound to a different release candidate', 'candidate_mismatch');
        }
    }

    // Final non-destructive gates.
    $liveVersion = $applier->readLiveVersion();
    if (
        !hash_equals((string) ($journalState['installed_version'] ?? ''), $liveVersion['version'])
        || (int) ($journalState['installed_version_code'] ?? -1) !== $liveVersion['version_code']
        || $liveVersion['version_code'] !== Version::VERSION_CODE
    ) {
        updateApplyFail('Live version changed since rollback checkpoint was created', 'installed_version_changed');
    }
    $health = updateApplyHealth($root);
    $dryRun = updateApplyMigrationDryRun($candidate['candidate_dir']);
    $candidate = $applier->verifyCandidateTree($candidate['candidate_dir']);
    $verifiedBackup = $backupManager->verify($verifiedBackup['backup_dir'], $transactionId);

    $ws = updateApplyWsStatus($root);
    if ($ws['running'] && !function_exists('pcntl_fork')) {
        updateApplyFail('Running WebSocket server requires CLI pcntl so updater can restart it after switch; stop it through the process manager or enable pcntl before apply', 'ws_restart_unavailable');
    }

    $journalState = $stateMachine->markPreflightVerified($transactionId, [
        'health_status' => $health['status'] ?? null,
        'migration_dry_run_sha256' => $dryRun['sha256'],
        'candidate_tree_sha256' => $candidate['tree_sha256'],
        'backup_manifest_sha256' => $verifiedBackup['manifest_sha256'],
        'ws_was_running' => $ws['running'],
        'verified_at' => time(),
    ]);

    // Record the destructive boundary BEFORE preparing/switching live entries.
    // A crash from here onward is intentionally recovered from verified backup.
    $stateMachine->markLiveMutationStarted($transactionId, [
        'started_at' => time(),
        'target_version' => $candidate['target_version'],
        'target_version_code' => $candidate['target_version_code'],
    ]);
    $mutationStarted = true;

    try {
        $plan = $applier->prepareCodeSwitch($transactionId, $candidate['candidate_dir'], $verifiedBackup['backup_dir']);
        $switch = $applier->switchPrepared($plan);
        $stateMachine->markCodeSwitched($transactionId, $switch);

        $migrate = updateApplyRun([PHP_BINARY, $root . '/bin/migrate.php'], $root, 300);
        if ($migrate['code'] !== 0) {
            throw new RuntimeException('Live migration failed: ' . trim($migrate['stderr'] . ' ' . $migrate['stdout']));
        }
        $stateMachine->markMigrationsApplied($transactionId, [
            'output_sha256' => hash('sha256', $migrate['stdout']),
            'completed_at' => time(),
        ]);

        $postHealth = updateApplyHealth($root);
        $postVersion = $applier->readLiveVersion();
        if (
            !hash_equals((string) ($journalState['target_version'] ?? ''), $postVersion['version'])
            || (int) ($journalState['target_version_code'] ?? -1) !== $postVersion['version_code']
        ) {
            throw new RuntimeException('Post-update live version does not match signed target');
        }
        $migrationStatus = updateApplyRun([PHP_BINARY, $root . '/bin/migrate.php', '--status'], $root, 180);
        if ($migrationStatus['code'] !== 0) {
            throw new RuntimeException('Post-update migration checksum/schema status failed: ' . trim($migrationStatus['stderr'] . ' ' . $migrationStatus['stdout']));
        }
        if ($ws['running']) {
            updateApplyRestartWs($root);
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
        $stateMachine->markCommitted($transactionId, ['committed_at' => time(), 'version' => $postVersion['version']]);
        $maintenance->leave($transactionId);

        $result = [
            'status' => 'committed',
            'transaction_id' => $transactionId,
            'installed_version' => $postVersion['version'],
            'installed_version_code' => $postVersion['version_code'],
            'maintenance_active' => false,
            'ws_restarted' => $ws['running'],
        ];
        if ($json) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        } else {
            echo "[OK] Update transaction committed: {$postVersion['version']}\n";
            echo "Maintenance released after version, migration and health verification.\n";
        }
        exit(0);
    } catch (Throwable $applyError) {
        $journalState = $stateMachine->load($transactionId);
        try {
            $verified = updateApplyRollback($transactionId, $journalState, $stateMachine, $applier, $backupManager, $root, $ws['running']);
            $maintenance->leave($transactionId);
            updateApplyFail(
                'Update failed after live mutation and was rolled back: ' . $applyError->getMessage(),
                'apply_rolled_back',
                1,
                ['rollback' => $verified, 'maintenance_active' => false]
            );
        } catch (Throwable $rollbackError) {
            try {
                $stateMachine->markRollbackFailed($transactionId, [
                    'apply_error' => $applyError->getMessage(),
                    'rollback_error' => $rollbackError->getMessage(),
                    'at' => time(),
                ]);
            } catch (Throwable) {
            }
            updateApplyFail(
                'Update failed and rollback did not verify; maintenance remains active. Apply error: ' . $applyError->getMessage() . '; rollback error: ' . $rollbackError->getMessage(),
                'rollback_failed',
                1,
                ['maintenance_active' => true]
            );
        }
    }
} catch (Throwable $e) {
    updateApplyFail($e->getMessage());
}
