<?php

declare(strict_types=1);

use App\Services\MaintenanceModeService;
use Core\UpdateApplyCommand;
use Core\UpdateBackupManager;
use Core\UpdateLiveApplier;
use Core\UpdateTransactionJournal;
use Core\UpdateTransactionStateMachine;

$root = dirname(__DIR__, 2);
require_once $root . '/app/services/MaintenanceModeService.php';
require_once $root . '/core/UpdateApplyCommand.php';
require_once $root . '/core/UpdateTransactionJournal.php';
require_once $root . '/core/UpdateTransactionStateMachine.php';
require_once $root . '/core/UpdateBackupManager.php';
require_once $root . '/core/UpdateLiveApplier.php';

function liveApplyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function liveApplyRemoveTree(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            liveApplyRemoveTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function liveApplyWrite(string $path, string $bytes): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        liveApplyAssert(mkdir($dir, 0700, true), 'unable to create fixture directory ' . $dir);
    }
    liveApplyAssert(file_put_contents($path, $bytes) === strlen($bytes), 'unable to write fixture ' . $path);
}

/** @param list<string> $files @return list<array{path:string,sha256:string,size:int,mode:int}> */
function liveApplyCodeEntries(string $root, array $files): array
{
    $entries = [];
    foreach ($files as $relative) {
        $path = $root . '/' . $relative;
        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        liveApplyAssert(is_int($size) && is_string($hash), 'unable to hash code fixture');
        $entries[] = ['path' => $relative, 'sha256' => $hash, 'size' => $size, 'mode' => 0644];
    }
    usort($entries, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
    return $entries;
}

/** @param array<string,string> $files @return array<string,mixed> */
function liveApplyCandidate(string $dir, string $version, int $versionCode, array $files): array
{
    foreach ($files as $relative => $bytes) {
        liveApplyWrite($dir . '/' . $relative, $bytes);
    }
    $map = [];
    $total = 0;
    foreach (array_keys($files) as $relative) {
        $path = $dir . '/' . $relative;
        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        liveApplyAssert(is_int($size) && is_string($hash), 'unable to hash candidate fixture');
        $map[$relative] = ['sha256' => $hash, 'size' => $size];
        $total += $size;
    }
    ksort($map, SORT_STRING);
    $tree = [
        'schema' => 1,
        'archive_root' => 'workspace-organizer-contract',
        'target_version' => $version,
        'target_version_code' => $versionCode,
        'files' => $map,
        'file_count' => count($map),
        'total_bytes' => $total,
    ];
    $bytes = json_encode($tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    liveApplyWrite($dir . '/.workspace-release-tree.json', $bytes);
    return ['tree_sha256' => hash('sha256', $bytes), 'files' => count($map), 'bytes' => $total];
}

$tag = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) (getenv('LIVE_APPLY_CONTRACT_TAG') ?: PHP_VERSION));
$temp = sys_get_temp_dir() . '/wo-live-apply-contract-' . $tag;
liveApplyRemoveTree($temp);
liveApplyAssert(mkdir($temp, 0700, true), 'unable to create live apply temp root');

try {
    $wsStatusRoot = $temp . '/ws-status-root';
    liveApplyAssert(mkdir($wsStatusRoot . '/ws_server', 0700, true), 'unable to create WebSocket status fixture');
    liveApplyWrite(
        $wsStatusRoot . '/ws_server/server.php',
        "<?php\nfwrite(STDOUT, \"WebSocket-сервер не запущен.\\n\");\nexit(1);\n"
    );
    $wsCommand = new UpdateApplyCommand($wsStatusRoot);
    $wsMethod = new ReflectionMethod(UpdateApplyCommand::class, 'wsStatus');
    $wsState = $wsMethod->invoke($wsCommand, $wsStatusRoot);
    liveApplyAssert(
        ($wsState['running'] ?? true) === false,
        'Код 1 локализованной команды WebSocket status не распознан как остановленный процесс'
    );

    $applyCommandSource = (string) file_get_contents($root . '/core/UpdateApplyCommand.php');
    liveApplyAssert(
        str_contains($applyCommandSource, '($latest[\'live_mutation_started\'] ?? false) !== true'),
        'Потеряна проверка destructive boundary перед снятием maintenance'
    );
    liveApplyAssert(
        str_contains($applyCommandSource, '$maintenance->leave($transactionId);'),
        'Потеряно снятие maintenance при ошибке до изменения рабочих файлов'
    );

    $live = $temp . '/live';
    $candidateDir = $temp . '/candidate';
    $backupDir = $temp . '/backups/live-contract-001';
    $stageDir = $temp . '/stage';
    $stateRoot = $temp . '/state';
    foreach ([$live, $candidateDir, $backupDir . '/code', $stageDir, $stateRoot] as $dir) {
        liveApplyAssert(mkdir($dir, 0700, true), 'unable to create fixture root ' . $dir);
    }

    $oldVersion = '0.14.0-beta.4';
    $oldCode = 1404;
    $newVersion = '1.0.0-contract';
    $newCode = 10000;
    $oldFiles = [
        'index.php' => "<?php echo 'old';\n",
        'legacy.php' => "<?php echo 'legacy';\n",
        'core/Version.php' => "<?php\ndeclare(strict_types=1);\nnamespace Core;\nclass Version { public const VERSION = '{$oldVersion}'; public const VERSION_CODE = {$oldCode}; }\n",
        'core/Old.php' => "<?php echo 'old-core';\n",
        'bin/migrate.php' => "<?php echo 'old-migrate';\n",
        'bin/healthcheck.php' => "<?php echo json_encode(['status' => 'ok'], JSON_UNESCAPED_SLASHES), PHP_EOL;\n",
    ];
    foreach ($oldFiles as $relative => $bytes) {
        liveApplyWrite($live . '/' . $relative, $bytes);
        liveApplyWrite($backupDir . '/code/' . $relative, $bytes);
    }
    liveApplyWrite($live . '/.env', "SECRET=must-survive\n");
    liveApplyWrite($live . '/cache/runtime.txt', "cache-survives\n");
    liveApplyWrite($live . '/uploads/customer.bin', "user-data-survives\n");

    $entries = liveApplyCodeEntries($backupDir . '/code', array_keys($oldFiles));
    $codeManifest = [
        'schema' => 1,
        'files' => count($entries),
        'bytes' => array_sum(array_column($entries, 'size')),
        'excluded_roots' => ['cache', 'uploads'],
        'entries' => $entries,
    ];
    $codeManifestBytes = json_encode($codeManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    liveApplyWrite($backupDir . '/code-manifest.json', $codeManifestBytes);
    $codeManifestHash = hash('sha256', $codeManifestBytes);

    $databasePlaceholder = "-- database placeholder for filesystem contract\n";
    liveApplyWrite($backupDir . '/database.sql', $databasePlaceholder);
    $databaseMeta = [
        'path' => 'database.sql',
        'sha256' => hash('sha256', $databasePlaceholder),
        'bytes' => strlen($databasePlaceholder),
        'tables' => 1,
        'triggers' => 0,
        'table_metadata' => [['name' => 'placeholder', 'engine' => 'InnoDB', 'rows' => 0]],
        'format' => 'mysql-sql-v1',
    ];
    $backupJson = [
        'schema' => 1,
        'transaction_id' => 'live-contract-001',
        'created_at' => time(),
        'application_root' => $live,
        'code' => [
            'path' => 'code',
            'manifest' => 'code-manifest.json',
            'manifest_sha256' => $codeManifestHash,
            'files' => count($entries),
            'bytes' => array_sum(array_column($entries, 'size')),
        ],
        'database' => $databaseMeta,
    ];
    $backupBytes = json_encode($backupJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    liveApplyWrite($backupDir . '/backup.json', $backupBytes);

    $candidateMeta = liveApplyCandidate($candidateDir, $newVersion, $newCode, [
        'index.php' => "<?php echo 'new';\n",
        'core/Version.php' => "<?php\ndeclare(strict_types=1);\nnamespace Core;\nclass Version { public const VERSION = '{$newVersion}'; public const VERSION_CODE = {$newCode}; }\n",
        'core/New.php' => "<?php echo 'new-core';\n",
        'bin/migrate.php' => "<?php echo 'new-migrate';\n",
        'new.php' => "<?php echo 'new-only';\n",
    ]);

    $applier = new UpdateLiveApplier($live);
    $verified = $applier->verifyCandidateTree($candidateDir);
    liveApplyAssert($verified['tree_sha256'] === $candidateMeta['tree_sha256'], 'candidate tree hash changed during verification');
    liveApplyAssert($verified['target_version'] === $newVersion, 'candidate target version mismatch');

    $applier->assertMutablePathsSwitchSafe([$live . '/uploads', $temp . '/external-private']);
    $unsafeMutableRejected = false;
    liveApplyWrite($live . '/core/runtime/user.dat', 'mutable');
    try {
        $applier->assertMutablePathsSwitchSafe([$live . '/core/runtime']);
    } catch (Throwable $e) {
        $unsafeMutableRejected = str_contains($e->getMessage(), 'release-owned root core');
    }
    liveApplyAssert($unsafeMutableRejected, 'nested mutable path below release-owned root was accepted');
    @unlink($live . '/core/runtime/user.dat');
    @rmdir($live . '/core/runtime');

    $plan = $applier->prepareCodeSwitch('live-contract-001', $candidateDir, $backupDir);
    $switch = $applier->switchPrepared($plan);
    liveApplyAssert(in_array('core', $switch['entries'], true), 'core root was not switched');
    liveApplyAssert(file_get_contents($live . '/index.php') === "<?php echo 'new';\n", 'new index was not activated');
    liveApplyAssert(is_file($live . '/core/New.php') && !file_exists($live . '/core/Old.php'), 'core directory was not replaced as a release unit');
    liveApplyAssert(!file_exists($live . '/legacy.php') && is_file($live . '/new.php'), 'obsolete/new root files were not switched');
    liveApplyAssert(file_get_contents($live . '/.env') === "SECRET=must-survive\n", '.env was modified by live switch');
    liveApplyAssert(file_get_contents($live . '/cache/runtime.txt') === "cache-survives\n", 'cache root was modified by live switch');
    liveApplyAssert(file_get_contents($live . '/uploads/customer.bin') === "user-data-survives\n", 'uploads root was modified by live switch');

    $restored = $applier->restoreCode('live-contract-001', $backupDir, $candidateDir);
    liveApplyAssert($restored['entries'] !== [], 'rollback restored no release entries');
    liveApplyAssert(file_get_contents($live . '/index.php') === "<?php echo 'old';\n", 'old index was not restored');
    liveApplyAssert(is_file($live . '/core/Old.php') && !file_exists($live . '/core/New.php'), 'old core directory was not restored');
    liveApplyAssert(is_file($live . '/legacy.php') && !file_exists($live . '/new.php'), 'root file set was not restored');
    liveApplyAssert(file_get_contents($live . '/.env') === "SECRET=must-survive\n", '.env changed during rollback');
    liveApplyAssert(file_get_contents($live . '/uploads/customer.bin') === "user-data-survives\n", 'user upload changed during rollback');
    $version = $applier->readLiveVersion();
    liveApplyAssert($version['version'] === $oldVersion && $version['version_code'] === $oldCode, 'rollback version verification failed');

    // State graph: same external journal remains the source of truth.
    $journal = new UpdateTransactionJournal($stateRoot, $live);
    $packageSha = str_repeat('a', 64);
    $journal->initialize([
        'transaction_id' => 'live-state-001',
        'installed_version' => $oldVersion,
        'installed_version_code' => $oldCode,
        'target_version' => $newVersion,
        'target_version_code' => $newCode,
        'package_sha256' => $packageSha,
        'stage_dir' => $stageDir,
    ]);
    $backupManifestHash = hash_file('sha256', $backupDir . '/backup.json');
    liveApplyAssert(is_string($backupManifestHash), 'unable to hash backup manifest');
    // Give this state-machine transaction its own backup identity manifest.
    $stateBackupDir = $temp . '/backups/live-state-001';
    liveApplyAssert(mkdir($stateBackupDir, 0700), 'unable to create state backup dir');
    $stateBackupBytes = json_encode(['schema' => 1, 'transaction_id' => 'live-state-001', 'code' => [], 'database' => []], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    liveApplyWrite($stateBackupDir . '/backup.json', $stateBackupBytes);
    $journal->recordBackups('live-state-001', [
        'backup_dir' => $stateBackupDir,
        'manifest_path' => $stateBackupDir . '/backup.json',
        'manifest_sha256' => hash('sha256', $stateBackupBytes),
        'code' => [],
        'database' => [],
    ]);

    $machine = new UpdateTransactionStateMachine($stateRoot, $live);

    // Контур updater 1.0.6+ должен уметь привязать внешний runtime сразу
    // после проверенной резервной точки. Candidate затем повторно
    // проверяется новым apply и фиксируется в этом же журнале.
    $runtimeDir = $temp . '/external-runtime';
    liveApplyAssert(mkdir($runtimeDir, 0700), 'не удалось создать тестовый внешний runtime');
    liveApplyWrite($runtimeDir . '/entrypoint.php', "<?php\n");
    $runtimeManifest = "{}\n";
    liveApplyWrite($runtimeDir . '/runtime.json', $runtimeManifest);
    $runtimeState = $machine->attachExternalRuntime('live-state-001', [
        'runtime_root' => $runtimeDir,
        'entrypoint' => $runtimeDir . '/entrypoint.php',
        'manifest' => $runtimeDir . '/runtime.json',
        'manifest_sha256' => hash('sha256', $runtimeManifest),
        'source_version' => $oldVersion,
        'source_version_code' => $oldCode,
        'files' => 1,
    ]);
    liveApplyAssert(
        ($runtimeState['state'] ?? null) === 'backup_verified',
        'привязка внешнего runtime преждевременно изменила состояние транзакции'
    );
    liveApplyAssert(
        is_array($runtimeState['external_runtime'] ?? null),
        'внешний runtime не сохранился в журнале после backup_verified'
    );

    $machine->recordCandidate('live-state-001', [
        'candidate_dir' => $candidateDir,
        'tree_manifest' => $candidateDir . '/.workspace-release-tree.json',
        'tree_sha256' => $candidateMeta['tree_sha256'],
        'target_version' => $newVersion,
        'target_version_code' => $newCode,
        'files' => $candidateMeta['files'],
        'total_bytes' => $candidateMeta['bytes'],
    ]);
    $machine->markPreflightVerified('live-state-001', ['ok' => true]);
    $machine->markLiveMutationStarted('live-state-001', ['at' => time()]);
    $machine->markCodeSwitched('live-state-001', ['ok' => true]);
    $machine->markMigrationsApplied('live-state-001', ['ok' => true]);
    $machine->markRollbackStarted('live-state-001', ['reason' => 'contract']);
    $machine->markCodeRestored('live-state-001', ['ok' => true]);
    $machine->markRollbackFailed('live-state-001', ['reason' => 'simulated crash']);
    $machine->markRollbackStarted('live-state-001', ['reason' => 'retry']);
    $machine->markCodeRestored('live-state-001', ['ok' => true]);
    $machine->markDatabaseRestored('live-state-001', ['ok' => true]);
    $final = $machine->markRollbackVerified('live-state-001', ['ok' => true]);
    liveApplyAssert(($final['state'] ?? null) === 'rollback_verified', 'rollback state graph did not reach terminal verified state');
    liveApplyAssert(($final['live_mutation_started'] ?? false) === true, 'journal lost destructive-boundary marker');

    // Optional real MySQL restore contract. CI supplies this database.
    $dbName = trim((string) (getenv('DBNAME') ?: ''));
    if ($dbName !== '') {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new mysqli(
            (string) (getenv('DBHOST') ?: '127.0.0.1'),
            (string) (getenv('DBUSER') ?: 'root'),
            (string) (getenv('DBPASS') ?: ''),
            $dbName,
            (int) (getenv('DBPORT') ?: 3306)
        );
        $db->set_charset('utf8mb4');

        $dbBackupRoot = $temp . '/db-backups';
        $manager = new UpdateBackupManager($dbBackupRoot, $live, [$live . '/uploads']);
        $dbBackup = $manager->create('db-restore-001', $db);
        $beforeTitle = (string) ($db->query('SELECT title FROM items WHERE id=1')->fetch_assoc()['title'] ?? '');
        $beforeCount = (int) ($db->query('SELECT COUNT(*) AS c FROM items')->fetch_assoc()['c'] ?? -1);
        $db->query("UPDATE items SET title='mutated' WHERE id=1");
        $db->query('CREATE TABLE migration_leak (id INT PRIMARY KEY) ENGINE=InnoDB');
        $db->query("INSERT INTO items (title) VALUES ('new-after-backup')");

        $dbRestore = $applier->restoreDatabase($db, $dbBackup['backup_dir'], $dbBackup['database']);
        liveApplyAssert($dbRestore['tables'] >= 1, 'database restore did not verify tables');
        liveApplyAssert((string) ($db->query('SELECT title FROM items WHERE id=1')->fetch_assoc()['title'] ?? '') === $beforeTitle, 'database row content was not restored');
        liveApplyAssert((int) ($db->query('SELECT COUNT(*) AS c FROM items')->fetch_assoc()['c'] ?? -1) === $beforeCount, 'database row count was not restored');
        liveApplyAssert((int) ($db->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='migration_leak'")->fetch_assoc()['c'] ?? -1) === 0, 'failed-migration table survived rollback');

        // Сквозной crash-resume: новый процесс recovery должен продолжить
        // транзакцию после обрыва уже за destructive boundary.
        $crashTransaction = 'crash-recover-001';
        $crashStateRoot = $temp . '/crash-state';
        $crashBackupRoot = $temp . '/crash-backups';
        liveApplyAssert(mkdir($crashStateRoot, 0700, true), 'unable to create crash recovery state root');
        liveApplyAssert(mkdir($crashBackupRoot, 0700, true), 'unable to create crash recovery backup root');

        $crashBackupManager = new UpdateBackupManager($crashBackupRoot, $live, [$live . '/uploads']);
        $crashBackup = $crashBackupManager->create($crashTransaction, $db);
        $crashJournal = new UpdateTransactionJournal($crashStateRoot, $live);
        $crashJournal->initialize([
            'transaction_id' => $crashTransaction,
            'installed_version' => $oldVersion,
            'installed_version_code' => $oldCode,
            'target_version' => $newVersion,
            'target_version_code' => $newCode,
            'package_sha256' => str_repeat('c', 64),
            'stage_dir' => $stageDir,
        ]);
        $crashJournal->recordBackups($crashTransaction, $crashBackup);

        $crashMachine = new UpdateTransactionStateMachine($crashStateRoot, $live);
        $crashMachine->recordCandidate($crashTransaction, [
            'candidate_dir' => $candidateDir,
            'tree_manifest' => $candidateDir . '/.workspace-release-tree.json',
            'tree_sha256' => $candidateMeta['tree_sha256'],
            'target_version' => $newVersion,
            'target_version_code' => $newCode,
            'files' => $candidateMeta['files'],
            'total_bytes' => $candidateMeta['bytes'],
        ]);
        $crashMachine->markPreflightVerified($crashTransaction, [
            'health_status' => 'ok',
            'ws_was_running' => false,
        ]);

        $crashMaintenance = new MaintenanceModeService($crashStateRoot, $live);
        $crashMaintenance->enter($crashTransaction, 'Проверка recovery после аварийного обрыва');
        $crashMachine->markLiveMutationStarted($crashTransaction, ['at' => time()]);

        $crashPlan = $applier->prepareCodeSwitch($crashTransaction, $candidateDir, $crashBackup['backup_dir']);
        $crashSwitch = $applier->switchPrepared($crashPlan);
        $crashMachine->markCodeSwitched($crashTransaction, $crashSwitch);
        liveApplyAssert(
            (string) ($applier->readLiveVersion()['version'] ?? '') === $newVersion,
            'crash fixture did not cross the live code switch boundary'
        );

        $db->query("UPDATE items SET title='crash-mutated', metadata=JSON_OBJECT('kind','mutated') WHERE id=1");
        $db->query('CREATE TABLE crash_recovery_leak (id INT PRIMARY KEY) ENGINE=InnoDB');

        // Здесь исходный updater считается погибшим: catch/rollback не вызываются.
        // Новый экземпляр команды обязан восстановиться только по durable journal.
        $recoverCommand = new UpdateApplyCommand($live, true);
        $recoverResult = $recoverCommand->execute([
            'recover' => true,
            'transaction' => $crashTransaction,
            'state-root' => $crashStateRoot,
            'backup-root' => $crashBackupRoot,
        ]);

        liveApplyAssert(($recoverResult['status'] ?? null) === 'rolled_back', 'crash recovery did not finish with rolled_back');
        $recoveredJournal = $crashMachine->load($crashTransaction);
        liveApplyAssert(($recoveredJournal['state'] ?? null) === 'rollback_verified', 'crash recovery did not reach rollback_verified');
        liveApplyAssert(($recoveredJournal['live_mutation_started'] ?? false) === true, 'crash recovery lost destructive-boundary marker');
        liveApplyAssert(!$crashMaintenance->state()['active'], 'crash recovery left maintenance active');
        liveApplyAssert((string) ($applier->readLiveVersion()['version'] ?? '') === $oldVersion, 'crash recovery did not restore old code');
        liveApplyAssert((string) ($db->query('SELECT title FROM items WHERE id=1')->fetch_assoc()['title'] ?? '') === $beforeTitle, 'crash recovery did not restore database row');
        liveApplyAssert((string) ($db->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.kind')) AS kind FROM items WHERE id=1")->fetch_assoc()['kind'] ?? '') === 'source', 'crash recovery did not restore JSON data');
        liveApplyAssert((int) ($db->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='crash_recovery_leak'")->fetch_assoc()['c'] ?? -1) === 0, 'crash recovery left migration artifact in database');

        // Отдельная регрессия: новая версия уже переключена и миграции отмечены,
        // но post-healthcheck завершился ошибкой. Следующий recovery обязан
        // вернуть код и БД к проверенной резервной точке без ручного CLI.
        $healthTransaction = 'health-fail-001';
        $healthStateRoot = $temp . '/health-state';
        $healthBackupRoot = $temp . '/health-backups';
        $healthCandidateDir = $temp . '/health-candidate';
        foreach ([$healthStateRoot, $healthBackupRoot, $healthCandidateDir] as $dir) {
            liveApplyAssert(mkdir($dir, 0700, true), 'Не удалось создать fixture healthcheck recovery');
        }

        $healthBackupManager = new UpdateBackupManager($healthBackupRoot, $live, [$live . '/uploads']);
        $healthBackup = $healthBackupManager->create($healthTransaction, $db);
        $healthBeforeTitle = (string) ($db->query('SELECT title FROM items WHERE id=1')->fetch_assoc()['title'] ?? '');

        $healthCandidate = liveApplyCandidate($healthCandidateDir, $newVersion, $newCode, [
            'index.php' => "<?php echo 'health-target';\n",
            'core/Version.php' => "<?php\ndeclare(strict_types=1);\nnamespace Core;\nclass Version { public const VERSION = '{$newVersion}'; public const VERSION_CODE = {$newCode}; }\n",
            'core/New.php' => "<?php echo 'health-target-core';\n",
            'bin/migrate.php' => "<?php exit(0);\n",
            'bin/healthcheck.php' => "<?php echo json_encode(['status' => 'failed'], JSON_UNESCAPED_SLASHES), PHP_EOL; exit(1);\n",
            'new.php' => "<?php echo 'health-target-only';\n",
        ]);

        $healthJournal = new UpdateTransactionJournal($healthStateRoot, $live);
        $healthJournal->initialize([
            'transaction_id' => $healthTransaction,
            'installed_version' => $oldVersion,
            'installed_version_code' => $oldCode,
            'target_version' => $newVersion,
            'target_version_code' => $newCode,
            'package_sha256' => str_repeat('d', 64),
            'stage_dir' => $stageDir,
        ]);
        $healthJournal->recordBackups($healthTransaction, $healthBackup);

        $healthMachine = new UpdateTransactionStateMachine($healthStateRoot, $live);
        $healthMachine->recordCandidate($healthTransaction, [
            'candidate_dir' => $healthCandidateDir,
            'tree_manifest' => $healthCandidateDir . '/.workspace-release-tree.json',
            'tree_sha256' => $healthCandidate['tree_sha256'],
            'target_version' => $newVersion,
            'target_version_code' => $newCode,
            'files' => $healthCandidate['files'],
            'total_bytes' => $healthCandidate['bytes'],
        ]);
        $healthMachine->markPreflightVerified($healthTransaction, [
            'health_status' => 'ok',
            'ws_was_running' => false,
        ]);

        $healthMaintenance = new MaintenanceModeService($healthStateRoot, $live);
        $healthMaintenance->enter($healthTransaction, 'Проверка rollback после failed healthcheck');
        $healthMachine->markLiveMutationStarted($healthTransaction, ['at' => time()]);
        $healthPlan = $applier->prepareCodeSwitch(
            $healthTransaction,
            $healthCandidateDir,
            $healthBackup['backup_dir']
        );
        $healthSwitch = $applier->switchPrepared($healthPlan);
        $healthMachine->markCodeSwitched($healthTransaction, $healthSwitch);

        $db->query("UPDATE items SET title='healthcheck-mutated' WHERE id=1");
        $healthMachine->markMigrationsApplied($healthTransaction, ['completed_at' => time()]);

        $healthCommand = new UpdateApplyCommand($live, true);
        $healthMethod = new ReflectionMethod(UpdateApplyCommand::class, 'health');
        $healthFailed = false;
        try {
            $healthMethod->invoke($healthCommand, $live);
        } catch (Throwable $e) {
            $healthFailed = str_contains(strtolower($e->getMessage()), 'healthcheck failed');
        }
        liveApplyAssert($healthFailed, 'Намеренно сломанный post-healthcheck не был распознан');
        liveApplyAssert(
            (string) ($applier->readLiveVersion()['version'] ?? '') === $newVersion,
            'Healthcheck fixture не дошёл до целевой версии перед recovery'
        );

        $healthRecovery = $healthCommand->execute([
            'recover' => true,
            'transaction' => $healthTransaction,
            'state-root' => $healthStateRoot,
            'backup-root' => $healthBackupRoot,
        ]);

        liveApplyAssert(
            ($healthRecovery['status'] ?? null) === 'rolled_back',
            'Recovery после failed healthcheck не завершился подтверждённым rollback'
        );
        liveApplyAssert(
            ($healthMachine->load($healthTransaction)['state'] ?? null) === 'rollback_verified',
            'Recovery после failed healthcheck не достиг rollback_verified'
        );
        liveApplyAssert(
            !$healthMaintenance->state()['active'],
            'Recovery после failed healthcheck оставил maintenance активным'
        );
        liveApplyAssert(
            (string) ($applier->readLiveVersion()['version'] ?? '') === $oldVersion,
            'Recovery после failed healthcheck не вернул исходную версию'
        );
        liveApplyAssert(
            (string) ($db->query('SELECT title FROM items WHERE id=1')->fetch_assoc()['title'] ?? '') === $healthBeforeTitle,
            'Recovery после failed healthcheck не восстановил данные БД'
        );

        $db->close();
    }

    echo "[OK] updater live apply/rollback contract\n";
} finally {
    liveApplyRemoveTree($temp);
}
