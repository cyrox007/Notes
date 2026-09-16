<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

$runnerRoot = dirname(__DIR__);
require_once $runnerRoot . '/core/Environment.php';
require_once $runnerRoot . '/core/UpdateManifestVerifier.php';
require_once $runnerRoot . '/core/UpdatePackageStager.php';
require_once $runnerRoot . '/core/UpdateArchiveInspector.php';
require_once $runnerRoot . '/core/UpdateTransactionJournal.php';
require_once $runnerRoot . '/core/UpdateTransactionStateMachine.php';
require_once $runnerRoot . '/core/UpdateBackupManager.php';
require_once $runnerRoot . '/core/UpdateReleaseCandidate.php';
require_once $runnerRoot . '/core/UpdateLiveApplier.php';
require_once $runnerRoot . '/core/UpdateApplyOperationLock.php';
require_once $runnerRoot . '/core/UpdateRollbackCodeRestorer.php';
require_once $runnerRoot . '/app/services/MaintenanceModeService.php';
require_once $runnerRoot . '/core/UpdateApplyCommand.php';

use App\Services\MaintenanceModeService;
use Core\Environment;
use Core\UpdateApplyCommand;
use Core\UpdateApplyException;
use Core\UpdateArchiveInspector;
use Core\UpdateBackupManager;
use Core\UpdateManifestVerifier;
use Core\UpdatePackageStager;
use Core\UpdateReleaseCandidate;
use Core\UpdateTransactionJournal;

$options = getopt('', [
    'app-root:',
    'manifest:',
    'signature:',
    'package:',
    'transaction:',
    'stage-root:',
    'state-root:',
    'backup-root:',
    'candidate-root:',
    'expected-source-version:',
    'expected-source-version-code:',
    'recover',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Workspace Organizer trusted external bootstrap updater\n\n";
    echo "Use this only when the installed source release predates the signed updater runtime.\n";
    echo "The runner itself must come from a separately trusted release bundle and stay outside the live tree.\n\n";
    echo "Bootstrap an exact legacy source installation:\n";
    echo "  php bin/update_bootstrap.php --app-root=/srv/workspace \\\n";
    echo "      --manifest=/secure/release/update.json --signature=/secure/release/update.sig \\\n";
    echo "      --package=/secure/release/workspace-organizer.zip \\\n";
    echo "      --transaction=update-beta4-to-1-0 \\\n";
    echo "      --expected-source-version=0.14.0-beta.4 --expected-source-version-code=1404 \\\n";
    echo "      --stage-root=/var/lib/notes/update-staging --state-root=/var/lib/notes/update-state \\\n";
    echo "      --backup-root=/var/lib/notes/update-backups --candidate-root=/var/lib/notes/update-releases\n\n";
    echo "Recover an interrupted bootstrap transaction:\n";
    echo "  php bin/update_bootstrap.php --app-root=/srv/workspace \\\n";
    echo "      --transaction=update-beta4-to-1-0 --state-root=/var/lib/notes/update-state \\\n";
    echo "      --backup-root=/var/lib/notes/update-backups --recover\n\n";
    echo "No private signing key is accepted by this command. Trust comes only from this runner's public update-key registry.\n";
    exit(0);
}

$json = isset($options['json']);

/** @return never */
function bootstrapFail(string $message, string $code = 'bootstrap_failed', int $exitCode = 1, array $details = []): never
{
    global $json;
    if ($json) {
        echo json_encode(
            ['status' => 'fail', 'code' => $code, 'message' => $message] + $details,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . PHP_EOL;
    } else {
        fwrite(STDERR, "[FAIL] {$message}\n");
    }
    exit($exitCode);
}

function bootstrapNormalize(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/');
}

function bootstrapInside(string $path, string $parent): bool
{
    $path = bootstrapNormalize($path);
    $parent = bootstrapNormalize($parent);
    if (PHP_OS_FAMILY === 'Windows') {
        $path = strtolower($path);
        $parent = strtolower($parent);
    }
    return $path === $parent || str_starts_with($path . '/', $parent . '/');
}

function bootstrapAppRoot(string $value, string $runnerRoot): string
{
    $value = trim($value);
    if ($value === '' || is_link($value)) {
        bootstrapFail('--app-root must be an explicit non-symlink live application directory', 'invalid_app_root', 2);
    }
    $real = realpath($value);
    $runner = realpath($runnerRoot);
    if (!is_string($real) || !is_dir($real) || !is_string($runner)) {
        bootstrapFail('Live application root cannot be resolved', 'invalid_app_root', 2);
    }
    $real = bootstrapNormalize($real);
    $runner = bootstrapNormalize($runner);
    if (bootstrapInside($real, $runner) || bootstrapInside($runner, $real)) {
        bootstrapFail('Bootstrap runner and live application must be separate directory trees', 'unsafe_bootstrap_layout', 2);
    }
    return $real;
}

function bootstrapLocalFile(string $value, string $label, int $maxBytes = 0): string
{
    $value = trim($value);
    if ($value === '' || str_contains($value, '://')) {
        bootstrapFail("{$label} must be an explicit local file path", 'invalid_input', 2);
    }
    if (!is_file($value) || is_link($value) || !is_readable($value)) {
        bootstrapFail("{$label} must be a readable regular local file, not a symlink", 'invalid_input', 2);
    }
    if ($maxBytes > 0) {
        $size = filesize($value);
        if (!is_int($size) || $size <= 0 || $size > $maxBytes) {
            bootstrapFail("{$label} has an invalid size", 'invalid_input', 2);
        }
    }
    $real = realpath($value);
    if (!is_string($real)) {
        bootstrapFail("{$label} path cannot be resolved", 'invalid_input', 2);
    }
    return $real;
}

function bootstrapResolveRoot(string $explicit, string $envName, string $privateSuffix): string
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
    bootstrapFail("{$envName} is not configured and PRIVATE_STORAGE_PATH is unavailable", 'path_not_configured', 2);
}

function bootstrapTransactionId(string $value): string
{
    $value = trim($value);
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $value) !== 1) {
        bootstrapFail('A valid --transaction id is required', 'invalid_transaction', 2);
    }
    return $value;
}

function bootstrapDatabase(): \mysqli
{
    if (!extension_loaded('mysqli')) {
        throw new \RuntimeException('PHP mysqli extension is required for updater bootstrap');
    }
    $user = getenv('DBUSER');
    $name = getenv('DBNAME');
    if (!is_string($user) || trim($user) === '' || !is_string($name) || trim($name) === '') {
        throw new \RuntimeException('Database environment is incomplete');
    }
    \mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new \mysqli(
        (string) (getenv('DBHOST') ?: 'localhost'),
        trim($user),
        (string) (getenv('DBPASS') ?: ''),
        trim($name),
        (int) (getenv('DBPORT') ?: 3306)
    );
    $db->set_charset('utf8mb4');
    return $db;
}

/** @return list<string> */
function bootstrapMutablePaths(): array
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
        $value = trim($value);
        if (in_array($name, ['WS_PID_FILE', 'LOG_FILE'], true)) {
            $value = dirname($value);
        }
        $paths[] = $value;
    }
    return array_values(array_unique($paths));
}

/** @param array<string,mixed> $result */
function bootstrapPrint(array $result): void
{
    global $json;
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        return;
    }
    echo '[OK] ' . (string) ($result['status'] ?? 'bootstrap_completed') . PHP_EOL;
    echo 'Transaction: ' . (string) ($result['transaction_id'] ?? '') . PHP_EOL;
    if (isset($result['source_version'], $result['target_version'])) {
        echo 'Upgrade:     ' . $result['source_version'] . ' -> ' . $result['target_version'] . PHP_EOL;
    }
    echo 'Maintenance: ' . (!empty($result['maintenance_active']) ? 'ACTIVE' : 'released') . PHP_EOL;
}

/**
 * Release only this bootstrap transaction's valid maintenance marker while the
 * durable journal still proves that live mutation has not started. Any invalid
 * marker/journal state fails closed and is left for explicit operator recovery.
 */
function bootstrapReleasePreLiveMaintenance(
    ?MaintenanceModeService $maintenance,
    ?UpdateTransactionJournal $journal,
    string $transactionId,
    bool &$maintenanceOwned
): void {
    if (!$maintenanceOwned || !$maintenance instanceof MaintenanceModeService) {
        return;
    }

    try {
        $maintenanceState = $maintenance->state();
        if (!$maintenanceState['active']) {
            $maintenanceOwned = false;
            return;
        }
        if (!$maintenanceState['valid'] || !hash_equals($transactionId, (string) $maintenanceState['transaction_id'])) {
            return;
        }

        if ($journal instanceof UpdateTransactionJournal) {
            $journalState = $journal->load($transactionId);
            if (($journalState['live_mutation_started'] ?? true) !== false) {
                return;
            }
        }

        $maintenance->leave($transactionId);
        $maintenanceOwned = false;
    } catch (\Throwable) {
        // Fail closed: never turn an uncertain pre-live state into an implicit
        // maintenance release. The external journal/marker remain inspectable.
    }
}

$appRoot = bootstrapAppRoot((string) ($options['app-root'] ?? ''), $runnerRoot);
$transactionId = bootstrapTransactionId((string) ($options['transaction'] ?? ''));

try {
    Environment::load($appRoot . '/.env');
} catch (\Throwable $e) {
    bootstrapFail('Cannot load live installation environment: ' . $e->getMessage(), 'environment_failed', 2);
}

$stateRoot = bootstrapResolveRoot((string) ($options['state-root'] ?? ''), 'UPDATE_STATE_PATH', 'updates');
$backupRoot = bootstrapResolveRoot((string) ($options['backup-root'] ?? ''), 'UPDATE_BACKUP_PATH', 'update-backups');

if (isset($options['recover'])) {
    try {
        $result = (new UpdateApplyCommand($appRoot, $json))->execute([
            'transaction' => $transactionId,
            'state-root' => $stateRoot,
            'backup-root' => $backupRoot,
            'recover' => true,
        ]);
        bootstrapPrint($result + ['bootstrap_runner' => true]);
        exit(0);
    } catch (UpdateApplyException $e) {
        bootstrapFail($e->getMessage(), $e->errorCode, $e->exitCode, $e->details);
    } catch (\Throwable $e) {
        bootstrapFail($e->getMessage());
    }
}

$expectedSourceVersion = trim((string) ($options['expected-source-version'] ?? ''));
$expectedSourceCodeRaw = trim((string) ($options['expected-source-version-code'] ?? ''));
if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/', $expectedSourceVersion) !== 1) {
    bootstrapFail('--expected-source-version is required and invalid', 'invalid_source_version', 2);
}
if (preg_match('/^[1-9][0-9]{0,9}$/', $expectedSourceCodeRaw) !== 1) {
    bootstrapFail('--expected-source-version-code is required and invalid', 'invalid_source_version', 2);
}
$expectedSourceCode = (int) $expectedSourceCodeRaw;

// Deliberately load Version from the LIVE legacy installation, never from the
// bootstrap runner. UpdateApplyCommand's existing updater_version_mismatch guard
// therefore stays bound to the actual source installation even when the runner
// comes from a newer release bundle.
if (class_exists('Core\\Version', false)) {
    bootstrapFail('Bootstrap runner loaded a Version class before the live source version was bound', 'unsafe_version_binding', 2);
}
$liveVersionFile = $appRoot . '/core/Version.php';
if (!is_file($liveVersionFile) || is_link($liveVersionFile)) {
    bootstrapFail('Live source Version.php is missing or unsafe', 'invalid_source_version', 2);
}
require_once $liveVersionFile;
if (!class_exists('Core\\Version', false)) {
    bootstrapFail('Live source Version.php did not define Core\\Version', 'invalid_source_version', 2);
}
$sourceVersion = (string) \Core\Version::VERSION;
$sourceVersionCode = (int) \Core\Version::VERSION_CODE;
if (!hash_equals($expectedSourceVersion, $sourceVersion) || $sourceVersionCode !== $expectedSourceCode) {
    bootstrapFail(
        "Live source version {$sourceVersion} ({$sourceVersionCode}) does not match the explicitly approved bootstrap source",
        'source_version_mismatch',
        2,
        ['actual_source_version' => $sourceVersion, 'actual_source_version_code' => $sourceVersionCode]
    );
}

$manifestPath = bootstrapLocalFile(
    (string) ($options['manifest'] ?? ''),
    'manifest',
    UpdateManifestVerifier::MAX_MANIFEST_BYTES
);
$signaturePath = bootstrapLocalFile((string) ($options['signature'] ?? ''), 'signature', 2048);
$packagePath = bootstrapLocalFile((string) ($options['package'] ?? ''), 'package');
$stageRoot = bootstrapResolveRoot((string) ($options['stage-root'] ?? ''), 'UPDATE_STAGING_PATH', 'update-staging');
$candidateRoot = bootstrapResolveRoot((string) ($options['candidate-root'] ?? ''), 'UPDATE_RELEASE_PATH', 'update-releases');

$manifestBytes = file_get_contents($manifestPath);
$signatureToken = file_get_contents($signaturePath);
if (!is_string($manifestBytes) || !is_string($signatureToken)) {
    bootstrapFail('Unable to read update manifest or signature', 'read_failed');
}
$signatureToken = trim($signatureToken);

$maintenance = null;
$journal = null;
$maintenanceOwned = false;

try {
    $verifier = new UpdateManifestVerifier();
    if (!$verifier->hasTrustedKeys()) {
        throw new \RuntimeException(
            'No trusted update public keys are configured in the external bootstrap runner'
        );
    }
    $verification = $verifier->verify($manifestBytes, $signatureToken);
    if (!($verification['valid'] ?? false) || !is_array($verification['manifest'] ?? null)) {
        throw new \RuntimeException((string) ($verification['message'] ?? 'Update manifest verification failed'));
    }
    $manifest = $verification['manifest'];

    $stager = new UpdatePackageStager($appRoot);
    $stager->assertCompatibility($manifest, $sourceVersionCode, PHP_VERSION);
    $package = $stager->verifyPackage($manifest, $packagePath);
    $archive = (new UpdateArchiveInspector())->inspect($packagePath);

    $staged = $stager->stage(
        $manifest,
        $manifestBytes,
        $signatureToken,
        $packagePath,
        $stageRoot,
        $sourceVersionCode,
        PHP_VERSION
    );

    $maintenance = new MaintenanceModeService($stateRoot, $appRoot);
    $maintenance->enter($transactionId, 'Bootstrap update from ' . $sourceVersion . ' to ' . (string) $manifest['version']);
    $maintenanceOwned = true;

    $journal = new UpdateTransactionJournal($stateRoot, $appRoot);
    $journal->initialize([
        'transaction_id' => $transactionId,
        'installed_version' => $sourceVersion,
        'installed_version_code' => $sourceVersionCode,
        'target_version' => (string) $manifest['version'],
        'target_version_code' => (int) $manifest['version_code'],
        'package_sha256' => $package['sha256'],
        'stage_dir' => $staged['stage_dir'],
    ]);

    $db = bootstrapDatabase();
    try {
        $backupManager = new UpdateBackupManager($backupRoot, $appRoot, bootstrapMutablePaths());
        $backups = $backupManager->create($transactionId, $db);
    } finally {
        $db->close();
    }
    $journal->recordBackups($transactionId, $backups);

    // Re-verify the immutable staged artifact after rollback backup creation and
    // immediately before release-candidate extraction. The bootstrap path must
    // not create a weaker shortcut around the normal signed updater pipeline.
    $stageManifestPath = $staged['stage_dir'] . '/manifest.json';
    $stageSignaturePath = $staged['stage_dir'] . '/manifest.sig';
    $stageManifestBytes = file_get_contents($stageManifestPath);
    $stageSignatureToken = file_get_contents($stageSignaturePath);
    if (!is_string($stageManifestBytes) || !is_string($stageSignatureToken)) {
        throw new \RuntimeException('Verified stage metadata became unreadable after rollback checkpoint');
    }
    $stageVerification = $verifier->verify($stageManifestBytes, trim($stageSignatureToken));
    if (!($stageVerification['valid'] ?? false) || !is_array($stageVerification['manifest'] ?? null)) {
        throw new \RuntimeException('Verified stage signature no longer validates');
    }
    $stageManifest = $stageVerification['manifest'];
    if (
        (int) $stageManifest['version_code'] !== (int) $manifest['version_code']
        || !hash_equals((string) $stageManifest['package']['sha256'], $package['sha256'])
    ) {
        throw new \RuntimeException('Verified stage no longer matches the signed bootstrap release');
    }
    $stagedPackagePath = $staged['stage_dir'] . '/' . (string) $stageManifest['package']['filename'];
    $stager->assertCompatibility($stageManifest, $sourceVersionCode, PHP_VERSION);
    $stagedPackage = $stager->verifyPackage($stageManifest, $stagedPackagePath);
    (new UpdateArchiveInspector())->inspect($stagedPackagePath);
    if (!hash_equals($package['sha256'], $stagedPackage['sha256'])) {
        throw new \RuntimeException('Staged package hash changed after rollback checkpoint');
    }

    $candidate = (new UpdateReleaseCandidate($appRoot))->extract(
        $stagedPackagePath,
        $candidateRoot,
        $stageManifest
    );

    $applyResult = (new UpdateApplyCommand($appRoot, $json))->execute([
        'transaction' => $transactionId,
        'candidate-dir' => $candidate['candidate_dir'],
        'state-root' => $stateRoot,
        'backup-root' => $backupRoot,
        'apply' => true,
    ]);
    $maintenanceOwned = false;

    bootstrapPrint([
        'status' => (string) ($applyResult['status'] ?? 'committed'),
        'bootstrap_runner' => true,
        'transaction_id' => $transactionId,
        'source_version' => $sourceVersion,
        'source_version_code' => $sourceVersionCode,
        'target_version' => (string) $manifest['version'],
        'target_version_code' => (int) $manifest['version_code'],
        'key_id' => (string) ($verification['key_id'] ?? ''),
        'package_sha256' => $package['sha256'],
        'archive_entries' => (int) ($archive['entries'] ?? 0),
        'candidate_tree_sha256' => (string) ($candidate['tree_sha256'] ?? ''),
        'maintenance_active' => (bool) ($applyResult['maintenance_active'] ?? false),
    ]);
} catch (UpdateApplyException $e) {
    bootstrapReleasePreLiveMaintenance($maintenance, $journal, $transactionId, $maintenanceOwned);
    bootstrapFail(
        $e->getMessage(),
        $e->errorCode,
        $e->exitCode,
        array_merge($e->details, ['maintenance_active' => $maintenanceOwned])
    );
} catch (\Throwable $e) {
    bootstrapReleasePreLiveMaintenance($maintenance, $journal, $transactionId, $maintenanceOwned);
    bootstrapFail(
        $e->getMessage(),
        'bootstrap_failed',
        1,
        ['maintenance_active' => $maintenanceOwned]
    );
}
