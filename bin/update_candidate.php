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
require_once $root . '/core/UpdateManifestVerifier.php';
require_once $root . '/core/UpdatePackageStager.php';
require_once $root . '/core/UpdateArchiveInspector.php';
require_once $root . '/core/UpdateReleaseCandidate.php';
require_once $root . '/core/UpdateTransactionJournal.php';
require_once $root . '/app/services/MaintenanceModeService.php';

use App\Services\MaintenanceModeService;
use Core\UpdateArchiveInspector;
use Core\UpdateManifestVerifier;
use Core\UpdatePackageStager;
use Core\UpdateReleaseCandidate;
use Core\UpdateTransactionJournal;
use Core\Version;

$options = getopt('', [
    'transaction:',
    'state-root:',
    'candidate-root:',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Workspace Organizer verified external release-candidate extraction\n\n";
    echo "Prerequisites: the transaction owns maintenance and its journal is backup_verified.\n\n";
    echo "  php bin/update_candidate.php --transaction=update-... \\\n";
    echo "      [--state-root=/external/state] [--candidate-root=/external/releases] [--json]\n\n";
    echo "This command extracts only to an external candidate directory. It never overwrites live code or runs migrations.\n";
    exit(0);
}

$json = isset($options['json']);

/** @return never */
function updateCandidateFail(string $message, string $code = 'candidate_failed', int $exitCode = 1): never
{
    global $json;
    if ($json) {
        echo json_encode([
            'status' => 'fail',
            'code' => $code,
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, "[FAIL] {$message}\n");
    }
    exit($exitCode);
}

function updateCandidateResolveRoot(string $explicit, string $envName, string $privateSuffix): string
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
    updateCandidateFail("{$envName} is not configured and PRIVATE_STORAGE_PATH is unavailable", 'path_not_configured', 2);
}

$transactionId = trim((string) ($options['transaction'] ?? ''));
if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1) {
    updateCandidateFail('A valid --transaction id is required', 'invalid_transaction', 2);
}
$stateRoot = updateCandidateResolveRoot((string) ($options['state-root'] ?? ''), 'UPDATE_STATE_PATH', 'updates');
$candidateRoot = updateCandidateResolveRoot((string) ($options['candidate-root'] ?? ''), 'UPDATE_RELEASE_PATH', 'update-releases');

try {
    $maintenance = new MaintenanceModeService($stateRoot, $root);
    $maintenanceState = $maintenance->state();
    if (!$maintenanceState['active'] || !$maintenanceState['valid']) {
        updateCandidateFail('Release candidate extraction requires active valid maintenance', 'maintenance_required');
    }
    if (!hash_equals($transactionId, (string) $maintenanceState['transaction_id'])) {
        updateCandidateFail('Maintenance mode belongs to another updater transaction', 'maintenance_owner_mismatch');
    }

    $journal = new UpdateTransactionJournal($stateRoot, $root);
    $journalState = $journal->load($transactionId);
    if (($journalState['state'] ?? null) !== 'backup_verified') {
        updateCandidateFail('Updater transaction must have verified rollback backups before extraction', 'backup_required');
    }
    if (($journalState['live_mutation_started'] ?? true) !== false) {
        updateCandidateFail('Refusing candidate extraction after live mutation started', 'unsafe_transaction_state');
    }

    $stageDir = (string) ($journalState['stage_dir'] ?? '');
    if ($stageDir === '' || is_link($stageDir)) {
        updateCandidateFail('Transaction stage path is missing or unsafe', 'invalid_stage');
    }
    $stageReal = realpath($stageDir);
    if (!is_string($stageReal) || !is_dir($stageReal)) {
        updateCandidateFail('Transaction stage directory cannot be resolved', 'invalid_stage');
    }

    foreach (['manifest.json', 'manifest.sig', 'stage.json'] as $required) {
        $path = $stageReal . DIRECTORY_SEPARATOR . $required;
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            updateCandidateFail("Verified stage is missing safe {$required}", 'invalid_stage');
        }
    }
    $manifestBytes = file_get_contents($stageReal . '/manifest.json');
    $signatureToken = file_get_contents($stageReal . '/manifest.sig');
    $stageBytes = file_get_contents($stageReal . '/stage.json');
    if (!is_string($manifestBytes) || !is_string($signatureToken) || !is_string($stageBytes)) {
        updateCandidateFail('Cannot read staged update metadata', 'invalid_stage');
    }

    $verifier = new UpdateManifestVerifier();
    if (!$verifier->hasTrustedKeys()) {
        updateCandidateFail('No trusted update public keys are configured', 'trust_not_configured');
    }
    $verified = $verifier->verify($manifestBytes, trim($signatureToken));
    if (!($verified['valid'] ?? false) || !is_array($verified['manifest'] ?? null)) {
        updateCandidateFail((string) ($verified['message'] ?? 'Staged update signature is invalid'), (string) ($verified['code'] ?? 'manifest_invalid'));
    }
    $manifest = $verified['manifest'];
    $stageMetadata = json_decode($stageBytes, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($stageMetadata) || array_is_list($stageMetadata) || ($stageMetadata['state'] ?? null) !== 'verified_staged') {
        updateCandidateFail('Stage metadata is invalid', 'invalid_stage');
    }

    $packageName = (string) ($manifest['package']['filename'] ?? '');
    $packagePath = $stageReal . DIRECTORY_SEPARATOR . $packageName;
    $stager = new UpdatePackageStager($root);
    $stager->assertCompatibility($manifest, Version::VERSION_CODE, PHP_VERSION);
    $package = $stager->verifyPackage($manifest, $packagePath);
    (new UpdateArchiveInspector())->inspect($packagePath);

    if (
        !hash_equals((string) ($journalState['package_sha256'] ?? ''), $package['sha256'])
        || (int) ($journalState['target_version_code'] ?? -1) !== (int) $manifest['version_code']
        || (string) ($journalState['target_version'] ?? '') !== (string) $manifest['version']
        || !hash_equals((string) ($stageMetadata['package_sha256'] ?? ''), $package['sha256'])
    ) {
        updateCandidateFail('Transaction/stage metadata no longer matches the signed package', 'transaction_mismatch');
    }

    $candidate = (new UpdateReleaseCandidate($root))->extract($packagePath, $candidateRoot, $manifest);
    $result = [
        'status' => 'candidate_verified',
        'transaction_id' => $transactionId,
        'target_version' => (string) $manifest['version'],
        'target_version_code' => (int) $manifest['version_code'],
        'package_sha256' => $package['sha256'],
        'candidate_dir' => $candidate['candidate_dir'],
        'candidate_tree_sha256' => $candidate['tree_sha256'],
        'candidate_files' => $candidate['files'],
        'candidate_bytes' => $candidate['total_bytes'],
        'maintenance_active' => true,
        'live_files_changed' => false,
        'live_mutation_started' => false,
    ];

    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        echo "[OK] External update release candidate verified\n";
        echo "Transaction: {$transactionId}\n";
        echo "Target:      " . $manifest['version'] . "\n";
        echo "Candidate:   " . $candidate['candidate_dir'] . "\n";
        echo "Tree SHA:    " . $candidate['tree_sha256'] . "\n";
        echo "Maintenance remains ACTIVE. Live code and database were not changed.\n";
    }
} catch (Throwable $e) {
    updateCandidateFail($e->getMessage());
}
