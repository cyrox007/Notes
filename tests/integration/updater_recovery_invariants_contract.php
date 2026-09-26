<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/UpdateApplyOperationLock.php';
require_once dirname(__DIR__, 2) . '/core/UpdateRollbackCodeRestorer.php';

use Core\UpdateApplyOperationLock;
use Core\UpdateOperationBusyException;
use Core\UpdateRollbackCodeRestorer;

function fail_contract(string $message): never
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function assert_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fail_contract($message);
    }
}

function remove_contract_tree(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
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
            remove_contract_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function write_contract_file(string $path, string $bytes, int $mode = 0644): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fail_contract("cannot create fixture directory {$dir}");
    }
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        fail_contract("cannot write fixture {$path}");
    }
    @chmod($path, $mode);
}

$base = sys_get_temp_dir() . '/notes-updater-recovery-' . bin2hex(random_bytes(6));
$app = $base . '/app';
$stateRoot = $base . '/state';
$backupDir = $base . '/backups/update-contract01';
$transactionId = 'update-contract01';

try {
    foreach ([$app, $stateRoot, $backupDir . '/code/core', $app . '/uploads'] as $dir) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            fail_contract("cannot create fixture directory {$dir}");
        }
    }

    // Operation ownership must span the whole apply/recover command, so a
    // second process for the same transaction cannot enter the destructive path.
    $firstLock = new UpdateApplyOperationLock($stateRoot, $transactionId);
    $busyObserved = false;
    try {
        new UpdateApplyOperationLock($stateRoot, $transactionId);
    } catch (UpdateOperationBusyException) {
        $busyObserved = true;
    }
    assert_contract($busyObserved, 'second operation lock for the same transaction was not rejected');
    $firstLock->release();
    $afterRelease = new UpdateApplyOperationLock($stateRoot, $transactionId);
    $afterRelease->release();

    $oldVersion = "<?php\nfinal class FixtureVersion { public const VALUE = 'old'; }\n";
    $oldIndex = "<?php echo 'old';\n";
    write_contract_file($backupDir . '/code/core/Version.php', $oldVersion);
    write_contract_file($backupDir . '/code/index.php', $oldIndex);

    $entries = [
        [
            'path' => 'core/Version.php',
            'sha256' => hash('sha256', $oldVersion),
            'size' => strlen($oldVersion),
            'mode' => 0644,
        ],
        [
            'path' => 'index.php',
            'sha256' => hash('sha256', $oldIndex),
            'size' => strlen($oldIndex),
            'mode' => 0644,
        ],
    ];
    $codeManifest = [
        'schema' => 1,
        'files' => count($entries),
        'bytes' => strlen($oldVersion) + strlen($oldIndex),
        'excluded_roots' => ['.git', 'vendor', 'cache', 'compile', 'uploads', 'notes-private-storage', '.logs'],
        'entries' => $entries,
    ];
    $codeManifestBytes = json_encode(
        $codeManifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    write_contract_file($backupDir . '/code-manifest.json', $codeManifestBytes, 0600);

    $backupManifest = [
        'schema' => 1,
        'transaction_id' => $transactionId,
        'created_at' => time(),
        'application_root' => str_replace('\\', '/', realpath($app) ?: $app),
        'code' => [
            'path' => 'code',
            'manifest' => 'code-manifest.json',
            'manifest_sha256' => hash('sha256', $codeManifestBytes),
            'files' => count($entries),
            'bytes' => strlen($oldVersion) + strlen($oldIndex),
        ],
        'database' => ['path' => 'database.sql'],
    ];
    $backupManifestBytes = json_encode(
        $backupManifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    write_contract_file($backupDir . '/backup.json', $backupManifestBytes, 0600);

    // Simulate a switched release after the original candidate has disappeared.
    write_contract_file($app . '/core/Version.php', "<?php\nfinal class FixtureVersion { public const VALUE = 'new'; }\n");
    write_contract_file($app . '/index.php', "<?php echo 'new';\n");
    write_contract_file($app . '/new-feature/entry.php', "<?php echo 'target-only';\n");
    write_contract_file($app . '/.env', "SECRET=preserve-me\n", 0600);
    write_contract_file($app . '/uploads/user.txt', "persistent-user-data\n");

    $restored = (new UpdateRollbackCodeRestorer($app))->restore($transactionId, $backupDir);

    assert_contract(($restored['source'] ?? null) === 'verified_backup', 'rollback did not identify verified backup as its source');
    assert_contract(($restored['candidate_required'] ?? true) === false, 'rollback still reports a candidate dependency');
    assert_contract(file_get_contents($app . '/core/Version.php') === $oldVersion, 'old version file was not restored');
    assert_contract(file_get_contents($app . '/index.php') === $oldIndex, 'old index file was not restored');
    assert_contract(!file_exists($app . '/new-feature'), 'target-only top-level entry survived rollback');
    assert_contract(file_get_contents($app . '/.env') === "SECRET=preserve-me\n", '.env was modified by rollback');
    assert_contract(file_get_contents($app . '/uploads/user.txt') === "persistent-user-data\n", 'mutable upload data was modified by rollback');

    assert_contract(($restored['file_level'] ?? false) === true, 'rollback не подтвердил пофайловый режим');
    assert_contract(
        in_array('new-feature/entry.php', $restored['deleted_files'] ?? [], true),
        'target-only файл не был удалён пофайловым rollback'
    );
    assert_contract(!isset($restored['scratch_dir']), 'rollback не должен создавать scratch для переименования live-каталогов');

    echo "[OK] updater recovery ownership and candidate-independence contract\n";
} finally {
    remove_contract_tree($base);
}
