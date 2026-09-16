<?php

declare(strict_types=1);

use App\Services\MaintenanceModeService;
use Core\UpdateBackupManager;
use Core\UpdateTransactionJournal;

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/app/services/MaintenanceModeService.php';
require_once $repoRoot . '/core/UpdateTransactionJournal.php';
require_once $repoRoot . '/core/UpdateBackupManager.php';

function backupAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function backupRemoveTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            backupRemoveTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$tag = getenv('BACKUP_CONTRACT_TAG') ?: (PHP_MAJOR_VERSION . '_' . PHP_MINOR_VERSION);
$base = '/tmp/wo-update-backup-contract-' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $tag);
$appRoot = $base . '/app';
$stateRoot = $base . '/state';
$backupRoot = $base . '/backups';
$stageDir = $base . '/stage/101-deadbeef';
backupRemoveTree($base);
foreach ([$appRoot . '/nested', $appRoot . '/cache', $appRoot . '/uploads', $stateRoot, $backupRoot, $stageDir] as $dir) {
    backupAssert(mkdir($dir, 0700, true), "unable to create fixture directory {$dir}");
}

file_put_contents($appRoot . '/index.php', "<?php echo 'fixture';\n");
file_put_contents($appRoot . '/nested/config.php', "<?php return ['fixture' => true];\n");
file_put_contents($appRoot . '/binary.dat', "\x00\x01fixture\xff");
file_put_contents($appRoot . '/.env', "DBPASS=must-never-enter-code-backup\n");
file_put_contents($appRoot . '/cache/runtime.cache', 'mutable-cache');
file_put_contents($appRoot . '/uploads/user.bin', 'mutable-upload');
file_put_contents($stageDir . '/stage.json', "{}\n");

$transactionId = 'backup-contract-001';
$maintenance = new MaintenanceModeService($stateRoot, $appRoot);
$maintenanceState = $maintenance->enter($transactionId, 'Updater backup contract');
backupAssert($maintenanceState['active'] && $maintenanceState['valid'], 'maintenance did not activate');

$journal = new UpdateTransactionJournal($stateRoot, $appRoot);
$identity = [
    'transaction_id' => $transactionId,
    'installed_version' => '1.0.0-test',
    'installed_version_code' => 100,
    'target_version' => '1.0.1-test',
    'target_version_code' => 101,
    'package_sha256' => str_repeat('a', 64),
    'stage_dir' => $stageDir,
];
$initialized = $journal->initialize($identity);
backupAssert(($initialized['state'] ?? '') === 'initialized', 'journal did not initialize');
backupAssert(($initialized['live_mutation_started'] ?? true) === false, 'journal started with live mutation flag');
$again = $journal->initialize($identity);
backupAssert(($again['created_at'] ?? 0) === ($initialized['created_at'] ?? -1), 'same transaction initialization was not idempotent');

$identityCollision = $identity;
$identityCollision['package_sha256'] = str_repeat('b', 64);
$collisionRejected = false;
try {
    $journal->initialize($identityCollision);
} catch (Throwable $e) {
    $collisionRejected = str_contains($e->getMessage(), 'different package_sha256');
}
backupAssert($collisionRejected, 'transaction id collision with another package was not rejected');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(
    (string) (getenv('DBHOST') ?: '127.0.0.1'),
    (string) (getenv('DBUSER') ?: 'root'),
    (string) (getenv('DBPASS') ?: 'root'),
    (string) (getenv('DBNAME') ?: 'updater_backup_test'),
    (int) (getenv('DBPORT') ?: 3306)
);
$db->set_charset('utf8mb4');

try {
    $manager = new UpdateBackupManager($backupRoot, $appRoot);
    $backups = $manager->create($transactionId, $db);
    backupAssert(is_dir($backups['backup_dir']), 'backup directory missing');
    backupAssert(is_file($backups['manifest_path']), 'backup manifest missing');
    backupAssert(preg_match('/^[0-9a-f]{64}$/', $backups['manifest_sha256']) === 1, 'backup manifest hash invalid');
    backupAssert((int) ($backups['code']['files'] ?? 0) === 3, 'code snapshot did not include exactly immutable fixture files');
    backupAssert((int) ($backups['database']['tables'] ?? 0) >= 2, 'database backup table count too small');
    backupAssert((int) ($backups['database']['triggers'] ?? 0) === 1, 'database trigger was not backed up');

    $backupDir = $backups['backup_dir'];
    backupAssert(is_file($backupDir . '/code/index.php'), 'index.php missing from code snapshot');
    backupAssert(is_file($backupDir . '/code/nested/config.php'), 'nested code missing from snapshot');
    backupAssert(is_file($backupDir . '/code/binary.dat'), 'binary code fixture missing from snapshot');
    backupAssert(!file_exists($backupDir . '/code/.env'), '.env leaked into rollback code snapshot');
    backupAssert(!file_exists($backupDir . '/code/cache'), 'cache leaked into rollback code snapshot');
    backupAssert(!file_exists($backupDir . '/code/uploads'), 'uploads leaked into rollback code snapshot');

    $dump = file_get_contents($backupDir . '/database.sql');
    backupAssert(is_string($dump) && str_contains($dump, 'CREATE TABLE'), 'database dump lacks CREATE TABLE');
    backupAssert(str_contains($dump, 'INSERT INTO'), 'database dump lacks table data');
    backupAssert(str_contains($dump, 'CREATE') && str_contains($dump, 'TRIGGER'), 'database dump lacks trigger DDL');
    backupAssert(!str_contains($dump, 'must-never-enter-code-backup'), 'code secret leaked into database dump');

    $verifiedAgain = $manager->create($transactionId, $db);
    backupAssert($verifiedAgain['manifest_sha256'] === $backups['manifest_sha256'], 'repeat backup was not idempotent');

    $journalState = $journal->recordBackups($transactionId, $backups);
    backupAssert(($journalState['state'] ?? '') === 'backup_verified', 'journal did not record verified backup state');
    backupAssert(($journalState['live_mutation_started'] ?? true) === false, 'backup checkpoint incorrectly marks live mutation started');
    $journalAgain = $journal->recordBackups($transactionId, $backups);
    backupAssert(($journalAgain['state'] ?? '') === 'backup_verified', 'repeat backup journal write was not idempotent');

    $insideRootRejected = false;
    try {
        new UpdateBackupManager($appRoot . '/cache/unsafe-backups', $appRoot);
    } catch (Throwable $e) {
        $insideRootRejected = str_contains($e->getMessage(), 'outside the live application tree');
    }
    backupAssert($insideRootRejected, 'backup root inside live application tree was not rejected');

    $tamperPath = $backupDir . '/code/index.php';
    file_put_contents($tamperPath, "tampered\n");
    $tamperRejected = false;
    try {
        $manager->verify($backupDir, $transactionId);
    } catch (Throwable $e) {
        $tamperRejected = str_contains($e->getMessage(), 'verification failed');
    }
    backupAssert($tamperRejected, 'tampered code rollback artifact was accepted');
} finally {
    $db->close();
}

$maintenance->leave($transactionId);
backupAssert(!$maintenance->state()['active'], 'maintenance did not release after contract');

echo "[OK] updater transaction journal and backup contract\n";
echo "BACKUP_ROOT={$backupRoot}\n";
echo "TRANSACTION_ID={$transactionId}\n";
