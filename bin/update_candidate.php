<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/CliRuntime.php';
\Core\CliRuntime::loadEnvironment($root);
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
    echo "Подготовка проверенного внешнего кандидата релиза Workspace Organizer\n\n";
    echo "Условия: транзакция владеет режимом обслуживания, а журнал находится в состоянии backup_verified.\n\n";
    echo "  php bin/update_candidate.php --transaction=update-... \\\n";
    echo "      [--state-root=/external/state] [--candidate-root=/external/releases] [--json]\n\n";
    echo "Команда распаковывает пакет только во внешний каталог кандидата и не меняет рабочий код или БД.\n";
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
        fwrite(STDERR, "[ОШИБКА] {$message}\n");
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
    updateCandidateFail("{$envName} не настроен, а PRIVATE_STORAGE_PATH недоступен", 'path_not_configured', 2);
}

$transactionId = trim((string) ($options['transaction'] ?? ''));
if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1) {
    updateCandidateFail('Требуется корректный идентификатор --transaction', 'invalid_transaction', 2);
}
$stateRoot = updateCandidateResolveRoot((string) ($options['state-root'] ?? ''), 'UPDATE_STATE_PATH', 'updates');
$candidateRoot = updateCandidateResolveRoot((string) ($options['candidate-root'] ?? ''), 'UPDATE_RELEASE_PATH', 'update-releases');

try {
    $maintenance = new MaintenanceModeService($stateRoot, $root);
    $maintenanceState = $maintenance->state();
    if (!$maintenanceState['active'] || !$maintenanceState['valid']) {
        updateCandidateFail('Подготовка кандидата релиза требует активный и корректный режим обслуживания', 'maintenance_required');
    }
    if (!hash_equals($transactionId, (string) $maintenanceState['transaction_id'])) {
        updateCandidateFail('Режим обслуживания принадлежит другой транзакции updater', 'maintenance_owner_mismatch');
    }

    $journal = new UpdateTransactionJournal($stateRoot, $root);
    $journalState = $journal->load($transactionId);
    if (($journalState['state'] ?? null) !== 'backup_verified') {
        updateCandidateFail('До подготовки кандидата транзакция должна иметь проверенную резервную копию отката', 'backup_required');
    }
    if (($journalState['live_mutation_started'] ?? true) !== false) {
        updateCandidateFail('Подготовка кандидата запрещена после начала изменения рабочего дерева', 'unsafe_transaction_state');
    }

    $stageDir = (string) ($journalState['stage_dir'] ?? '');
    if ($stageDir === '' || is_link($stageDir)) {
        updateCandidateFail('Путь stage в транзакции отсутствует или небезопасен', 'invalid_stage');
    }
    $stageReal = realpath($stageDir);
    if (!is_string($stageReal) || !is_dir($stageReal)) {
        updateCandidateFail('Не удалось определить каталог stage транзакции', 'invalid_stage');
    }

    foreach (['manifest.json', 'manifest.sig', 'stage.json'] as $required) {
        $path = $stageReal . DIRECTORY_SEPARATOR . $required;
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            updateCandidateFail("В проверенном stage отсутствует безопасный файл {$required}", 'invalid_stage');
        }
    }
    $manifestBytes = file_get_contents($stageReal . '/manifest.json');
    $signatureToken = file_get_contents($stageReal . '/manifest.sig');
    $stageBytes = file_get_contents($stageReal . '/stage.json');
    if (!is_string($manifestBytes) || !is_string($signatureToken) || !is_string($stageBytes)) {
        updateCandidateFail('Не удалось прочитать метаданные подготовленного обновления', 'invalid_stage');
    }

    $verifier = new UpdateManifestVerifier();
    if (!$verifier->hasTrustedKeys()) {
        updateCandidateFail('Не настроены доверенные публичные ключи обновлений', 'trust_not_configured');
    }
    $verified = $verifier->verify($manifestBytes, trim($signatureToken));
    if (!($verified['valid'] ?? false) || !is_array($verified['manifest'] ?? null)) {
        updateCandidateFail((string) ($verified['message'] ?? 'Подпись подготовленного обновления недействительна'), (string) ($verified['code'] ?? 'manifest_invalid'));
    }
    $manifest = $verified['manifest'];
    $stageMetadata = json_decode($stageBytes, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($stageMetadata) || array_is_list($stageMetadata) || ($stageMetadata['state'] ?? null) !== 'verified_staged') {
        updateCandidateFail('Метаданные stage некорректны', 'invalid_stage');
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
        updateCandidateFail('Метаданные транзакции или stage больше не совпадают с подписанным пакетом', 'transaction_mismatch');
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
        echo "[OK] Внешний кандидат обновления проверен\n";
        echo "Транзакция: {$transactionId}\n";
        echo "Цель:       " . $manifest['version'] . "\n";
        echo "Кандидат:   " . $candidate['candidate_dir'] . "\n";
        echo "SHA дерева: " . $candidate['tree_sha256'] . "\n";
        echo "Режим обслуживания остаётся АКТИВНЫМ. Рабочий код и БД не изменялись.\n";
    }
} catch (Throwable $e) {
    updateCandidateFail($e->getMessage());
}
