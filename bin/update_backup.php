<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/CliRuntime.php';
\Core\CliRuntime::loadEnvironment($root);
require_once $root . '/core/Version.php';
require_once $root . '/core/UpdateManifestVerifier.php';
require_once $root . '/core/UpdatePackageStager.php';
require_once $root . '/core/UpdateArchiveInspector.php';
require_once $root . '/core/UpdateTransactionJournal.php';
require_once $root . '/core/UpdateBackupManager.php';
require_once $root . '/app/services/MaintenanceModeService.php';

use App\Services\MaintenanceModeService;
use Core\UpdateArchiveInspector;
use Core\UpdateBackupManager;
use Core\UpdateManifestVerifier;
use Core\UpdatePackageStager;
use Core\UpdateTransactionJournal;
use Core\Version;

$options = getopt('', [
    'transaction:',
    'stage-dir:',
    'state-root:',
    'backup-root:',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Контрольная точка резервной копии updater Workspace Organizer\n\n";
    echo "Условие: эта же транзакция уже должна владеть режимом обслуживания.\n\n";
    echo "  php bin/update_backup.php --transaction=update-... --stage-dir=/external/stage/... \\\n";
    echo "      [--state-root=/external/state] [--backup-root=/external/backups] [--json]\n\n";
    echo "Команда не распаковывает и не перезаписывает рабочий код приложения и не запускает миграции.\n";
    echo "После успеха режим обслуживания остаётся активным и снимается только владельцем транзакции.\n";
    exit(0);
}

$json = isset($options['json']);

/** @return never */
function updateBackupFail(string $message, string $code = 'backup_failed', int $exitCode = 1): never
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

function updateBackupResolveRoot(string $explicit, string $envName, string $privateSuffix): string
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
    updateBackupFail("{$envName} не настроен, а PRIVATE_STORAGE_PATH недоступен", 'path_not_configured', 2);
}

function updateBackupDb(): mysqli
{
    if (!extension_loaded('mysqli')) {
        updateBackupFail('Для резервной копии БД updater требуется расширение PHP mysqli', 'mysqli_missing', 2);
    }
    $user = getenv('DBUSER');
    $name = getenv('DBNAME');
    if (!is_string($user) || trim($user) === '' || !is_string($name) || trim($name) === '') {
        updateBackupFail('Конфигурация окружения БД неполная', 'database_config_missing', 2);
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

function updateBackupSafeStage(string $path, string $appRoot): string
{
    $path = trim($path);
    if ($path === '' || is_link($path)) {
        updateBackupFail('Каталог stage должен быть задан явно и не должен быть символической ссылкой', 'invalid_stage', 2);
    }
    $real = realpath($path);
    $app = realpath($appRoot);
    if (!is_string($real) || !is_dir($real) || !is_string($app)) {
        updateBackupFail('Не удалось определить каталог stage', 'invalid_stage', 2);
    }
    $realNorm = rtrim(str_replace('\\', '/', $real), '/');
    $appNorm = rtrim(str_replace('\\', '/', $app), '/');
    if (PHP_OS_FAMILY === 'Windows') {
        $realNorm = strtolower($realNorm);
        $appNorm = strtolower($appNorm);
    }
    if ($realNorm === $appNorm || str_starts_with($realNorm . '/', $appNorm . '/')) {
        updateBackupFail('Каталог stage должен находиться вне рабочего дерева приложения', 'invalid_stage', 2);
    }
    return $real;
}

$transactionId = trim((string) ($options['transaction'] ?? ''));
if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1) {
    updateBackupFail('Требуется корректный идентификатор --transaction', 'invalid_transaction', 2);
}
$stageDir = updateBackupSafeStage((string) ($options['stage-dir'] ?? ''), $root);
$stateRoot = updateBackupResolveRoot((string) ($options['state-root'] ?? ''), 'UPDATE_STATE_PATH', 'updates');
$backupRoot = updateBackupResolveRoot((string) ($options['backup-root'] ?? ''), 'UPDATE_BACKUP_PATH', 'update-backups');

try {
    $maintenance = new MaintenanceModeService($stateRoot, $root);
    $maintenanceState = $maintenance->state();
    if (!$maintenanceState['active'] || !$maintenanceState['valid']) {
        updateBackupFail('Резервная копия отката требует активную и корректную транзакцию режима обслуживания', 'maintenance_required');
    }
    if (!hash_equals($transactionId, (string) $maintenanceState['transaction_id'])) {
        updateBackupFail('Режим обслуживания принадлежит другой транзакции updater', 'maintenance_owner_mismatch');
    }

    foreach (['manifest.json', 'manifest.sig', 'stage.json'] as $required) {
        $path = $stageDir . DIRECTORY_SEPARATOR . $required;
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            updateBackupFail("В проверенном stage отсутствует безопасный файл {$required}", 'invalid_stage');
        }
    }

    $manifestBytes = file_get_contents($stageDir . DIRECTORY_SEPARATOR . 'manifest.json');
    $signatureToken = file_get_contents($stageDir . DIRECTORY_SEPARATOR . 'manifest.sig');
    $stageBytes = file_get_contents($stageDir . DIRECTORY_SEPARATOR . 'stage.json');
    if (!is_string($manifestBytes) || !is_string($signatureToken) || !is_string($stageBytes)) {
        updateBackupFail('Не удалось прочитать метаданные проверенного stage', 'invalid_stage');
    }

    $verifier = new UpdateManifestVerifier();
    if (!$verifier->hasTrustedKeys()) {
        updateBackupFail('Не настроены доверенные публичные ключи обновлений', 'trust_not_configured');
    }
    $verified = $verifier->verify($manifestBytes, trim($signatureToken));
    if (!($verified['valid'] ?? false) || !is_array($verified['manifest'] ?? null)) {
        updateBackupFail((string) ($verified['message'] ?? 'Подпись подготовленного обновления недействительна'), (string) ($verified['code'] ?? 'manifest_invalid'));
    }
    $manifest = $verified['manifest'];

    $stageMetadata = json_decode($stageBytes, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($stageMetadata) || array_is_list($stageMetadata) || ($stageMetadata['state'] ?? null) !== 'verified_staged') {
        updateBackupFail('Метаданные stage не описывают проверенное подготовленное обновление', 'invalid_stage');
    }

    $packageName = (string) ($manifest['package']['filename'] ?? '');
    $packagePath = $stageDir . DIRECTORY_SEPARATOR . $packageName;
    $stager = new UpdatePackageStager($root);
    $stager->assertCompatibility($manifest, Version::VERSION_CODE, PHP_VERSION);
    $package = $stager->verifyPackage($manifest, $packagePath);
    (new UpdateArchiveInspector())->inspect($packagePath);

    if (
        (int) ($stageMetadata['current_version_code'] ?? -1) !== Version::VERSION_CODE
        || (int) ($stageMetadata['target_version_code'] ?? -1) !== (int) $manifest['version_code']
        || (string) ($stageMetadata['target_version'] ?? '') !== (string) $manifest['version']
        || !hash_equals((string) ($stageMetadata['package_sha256'] ?? ''), $package['sha256'])
    ) {
        updateBackupFail('Метаданные stage больше не совпадают с установленной/целевой версией или хешем пакета', 'invalid_stage');
    }

    $journal = new UpdateTransactionJournal($stateRoot, $root);
    $journalState = $journal->initialize([
        'transaction_id' => $transactionId,
        'installed_version' => Version::VERSION,
        'installed_version_code' => Version::VERSION_CODE,
        'target_version' => (string) $manifest['version'],
        'target_version_code' => (int) $manifest['version_code'],
        'package_sha256' => $package['sha256'],
        'stage_dir' => $stageDir,
    ]);
    if (($journalState['live_mutation_started'] ?? true) !== false) {
        updateBackupFail('Журнал транзакции указывает, что изменение рабочего дерева уже началось', 'unsafe_transaction_state');
    }

    $excluded = [];
    foreach (['PRIVATE_STORAGE_PATH', 'UPLOAD_DIR', 'NOTES_UPLOAD_DIR', 'MESSENGER_UPLOAD_DIR', 'UPDATE_STAGING_PATH', 'UPDATE_STATE_PATH', 'UPDATE_BACKUP_PATH'] as $envName) {
        $value = getenv($envName);
        if (is_string($value) && trim($value) !== '') {
            $excluded[] = trim($value);
        }
    }

    $db = updateBackupDb();
    try {
        $backupManager = new UpdateBackupManager($backupRoot, $root, $excluded);
        $backups = $backupManager->create($transactionId, $db);
    } finally {
        $db->close();
    }
    $journalState = $journal->recordBackups($transactionId, $backups);

    $result = [
        'status' => 'backup_verified',
        'transaction_id' => $transactionId,
        'target_version' => (string) $manifest['version'],
        'package_sha256' => $package['sha256'],
        'backup_dir' => $backups['backup_dir'],
        'backup_manifest_sha256' => $backups['manifest_sha256'],
        'code_files' => $backups['code']['files'] ?? null,
        'database_tables' => $backups['database']['tables'] ?? null,
        'journal_path' => $journal->path($transactionId),
        'maintenance_active' => true,
        'live_files_changed' => false,
        'live_mutation_started' => $journalState['live_mutation_started'] ?? null,
    ];

    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        echo "[OK] Резервная копия отката updater проверена\n";
        echo "Транзакция: {$transactionId}\n";
        echo "Цель:       " . $manifest['version'] . "\n";
        echo "Копия:      " . $backups['backup_dir'] . "\n";
        echo "Манифест:   " . $backups['manifest_sha256'] . "\n";
        echo "Режим обслуживания остаётся АКТИВНЫМ. Рабочие файлы приложения и схема БД не изменялись.\n";
    }
} catch (Throwable $e) {
    updateBackupFail($e->getMessage());
}
