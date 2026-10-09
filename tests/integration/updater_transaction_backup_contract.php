<?php

declare(strict_types=1);

use App\Services\MaintenanceModeService;
use Core\UpdateBackupManager;
use Core\UpdateDatabaseRestorer;
use Core\UpdateTransactionJournal;

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/app/services/MaintenanceModeService.php';
require_once $repoRoot . '/core/UpdateTransactionJournal.php';
require_once $repoRoot . '/core/UpdateBackupManager.php';
require_once $repoRoot . '/core/UpdateDatabaseRestorer.php';

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
mkdir($appRoot . '/update-continuations', 0755);
file_put_contents($appRoot . '/update-continuations/entry.php', '<?php // fixed external entry');
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
    $resumable = in_array('--resumable', $argv, true);
    $pauses = 0;
    do {
        try {
            $backups = (new UpdateBackupManager($backupRoot, $appRoot))->create(
                $transactionId, $db, $resumable ? new Core\UpdateStepBudget(1) : null);
            break;
        } catch (Core\UpdateStepPending) {
            ++$pauses;
            backupAssert($pauses < 20, 'Резервирование не продвигается');
            if ($pauses === 1) {
                file_put_contents($backupRoot . '/.pending-' . $transactionId . '/code/binary.dat', 'partial copy');
            }
        }
    } while (true);
    if ($resumable) {
        backupAssert($pauses >= 3, 'Копирование кода не разбито на шаги');
        // Смерть между записью manifest и атомарной публикацией каталога.
        backupAssert(rename($backups['backup_dir'], $backupRoot . '/.pending-' . $transactionId), 'Нет фикстуры публикации');
        $again = $manager->create($transactionId, $db, new Core\UpdateStepBudget(1));
        backupAssert($again['manifest_sha256'] === $backups['manifest_sha256'], 'Повтор изменил готовый snapshot');
    }
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
    backupAssert(!file_exists($backupDir . '/code/update-continuations'), 'HTTP-вход попал в rollback snapshot');

    $dump = file_get_contents($backupDir . '/database.sql');
    backupAssert(is_string($dump) && str_contains($dump, 'CREATE TABLE'), 'database dump lacks CREATE TABLE');
    backupAssert(str_contains($dump, 'INSERT INTO'), 'database dump lacks table data');
    backupAssert(
        str_contains($dump, 'CONVERT(X\'') && str_contains($dump, 'USING utf8mb4)'),
        'JSON values are not serialized with an explicit UTF-8 conversion'
    );
    backupAssert(str_contains($dump, 'CREATE') && str_contains($dump, 'TRIGGER'), 'database dump lacks trigger DDL');
    backupAssert(
        stripos($dump, 'CREATE DEFINER=') === false,
        'rollback-дамп не должен привязывать триггер к DEFINER исходного сервера'
    );
    backupAssert(!str_contains($dump, 'must-never-enter-code-backup'), 'code secret leaked into database dump');

    $restorer = new UpdateDatabaseRestorer();
    // Hash корректен, но SQL оборван: таблицы должны остаться нетронутыми.
    $invalidDir = $base . '/invalid-db';
    backupAssert(mkdir($invalidDir, 0700, true), 'Не удалось создать каталог оборванного дампа');
    $invalidSql = "SET NAMES utf8mb4;\nCREATE TABLE unfinished (id INT)";
    file_put_contents($invalidDir . '/database.sql', $invalidSql);
    $invalidMetadata = $backups['database'];
    $invalidMetadata['bytes'] = strlen($invalidSql);
    $invalidMetadata['sha256'] = hash('sha256', $invalidSql);
    $invalidRejected = false;
    try { $restorer->restore($db, $invalidDir, $invalidMetadata); }
    catch (RuntimeException $error) { $invalidRejected = true; }
    backupAssert($invalidRejected, 'Оборванный дамп принят');
    backupAssert((int) $db->query('SELECT COUNT(*) AS c FROM items')->fetch_assoc()['c'] === 2,
        'Рабочие таблицы удалены до проверки SQL');
    $restored = $restorer->restore($db, $backupDir, $backups['database']);
    backupAssert((int) ($restored['tables'] ?? 0) >= 2, 'Новый rollback-дамп не восстановил таблицы');
    backupAssert(
        (string) ($db->query("SELECT title FROM items WHERE id=1")->fetch_assoc()['title'] ?? '') === 'Привет rollback',
        'Unicode-текст изменился после восстановления нового rollback-дампа'
    );
    backupAssert(
        strtoupper((string) ($db->query("SELECT HEX(payload) AS value FROM items WHERE id=1")->fetch_assoc()['value'] ?? '')) === '000102FF',
        'BLOB изменился после восстановления нового rollback-дампа'
    );
    backupAssert(
        (string) ($db->query("SELECT notes AS value FROM items WHERE id=1")->fetch_assoc()['value'] ?? '') === 'Текстовый rollback Ω',
        'TEXT изменился после восстановления нового rollback-дампа'
    );
    backupAssert(
        (string) ($db->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.kind')) AS value FROM items WHERE id=1")->fetch_assoc()['value'] ?? '') === 'rollback',
        'JSON изменился после восстановления нового rollback-дампа'
    );

    // Каждый вызов завершает одну операцию; новый объект имитирует новый HTTP-запрос.
    $pauses = 0;
    $stepped = null;
    for ($attempt = 0; $attempt < 200 && $stepped === null; ++$attempt) {
        try {
            $stepped = (new UpdateDatabaseRestorer())->restore(
                $db, $backupDir, $backups['database'], new Core\UpdateStepBudget(1)
            );
        } catch (Core\UpdateStepPending $pause) {
            ++$pauses;
        }
    }
    backupAssert($stepped !== null && $pauses > 5, 'Пошаговое восстановление не завершилось через контрольные точки');
    backupAssert((int) $db->query('SELECT COUNT(*) AS c FROM items')->fetch_assoc()['c'] === 2,
        'Повторные запросы продублировали данные');
    backupAssert((int) $db->query('SELECT COUNT(*) AS c FROM audit')->fetch_assoc()['c'] === 2,
        'Повторное восстановление изменило аудит');

    $legacyDir = $base . '/legacy-db';
    backupAssert(mkdir($legacyDir, 0700, true), 'Не удалось создать каталог legacy rollback');
    $legacySql = preg_replace(
        "/CONVERT\\((X'[0-9A-F]*') USING utf8mb4\\)/i",
        '$1',
        $dump
    );
    backupAssert(is_string($legacySql) && $legacySql !== $dump, 'Не удалось сформировать legacy mysql-sql-v1 fixture');
    $legacySql = preg_replace(
        '/CREATE\\s+TRIGGER\\b/i',
        'CREATE DEFINER=`root`@`%` TRIGGER',
        $legacySql,
        1
    );
    backupAssert(
        is_string($legacySql) && stripos($legacySql, 'CREATE DEFINER=') !== false,
        'Не удалось сформировать legacy rollback с чужим DEFINER'
    );
    $legacyPath = $legacyDir . '/database.sql';
    backupAssert(file_put_contents($legacyPath, $legacySql, LOCK_EX) === strlen($legacySql), 'Не удалось записать legacy rollback fixture');

    $legacyMetadata = $backups['database'];
    $legacyMetadata['path'] = 'database.sql';
    $legacyMetadata['bytes'] = filesize($legacyPath);
    $legacyMetadata['sha256'] = hash_file('sha256', $legacyPath);
    backupAssert(is_int($legacyMetadata['bytes']) && is_string($legacyMetadata['sha256']), 'Некорректные метаданные legacy rollback fixture');

    $legacyRestored = (new UpdateDatabaseRestorer())->restore($db, $legacyDir, $legacyMetadata);
    backupAssert((int) ($legacyRestored['tables'] ?? 0) >= 2, 'Legacy rollback не восстановил таблицы');
    backupAssert(
        (string) ($db->query("SELECT title FROM items WHERE id=1")->fetch_assoc()['title'] ?? '') === 'Привет rollback',
        'Unicode-текст изменился после восстановления legacy rollback'
    );
    backupAssert(
        strtoupper((string) ($db->query("SELECT HEX(payload) AS value FROM items WHERE id=1")->fetch_assoc()['value'] ?? '')) === '000102FF',
        'BLOB изменился после восстановления legacy rollback'
    );
    backupAssert(
        (string) ($db->query("SELECT notes AS value FROM items WHERE id=1")->fetch_assoc()['value'] ?? '') === 'Текстовый rollback Ω',
        'TEXT изменился после восстановления legacy rollback'
    );
    backupAssert(
        (string) ($db->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.kind')) AS value FROM items WHERE id=1")->fetch_assoc()['value'] ?? '') === 'rollback',
        'Legacy X\'HEX\' JSON не был восстановлен как UTF-8 JSON'
    );
    backupAssert(
        (string) ($db->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.enabled')) AS value FROM items WHERE id=2")->fetch_assoc()['value'] ?? '') === 'true',
        'Булево JSON-значение изменилось после legacy rollback'
    );

    $verifiedAgain = $manager->create($transactionId, $db);
    backupAssert($verifiedAgain['manifest_sha256'] === $backups['manifest_sha256'], 'repeat backup was not idempotent');

    $forgedBackups = $backups;
    $forgedBackups['manifest_sha256'] = str_repeat('0', 64);
    $forgedHashRejected = false;
    try {
        $journal->recordBackups($transactionId, $forgedBackups);
    } catch (Throwable $e) {
        $forgedHashRejected = str_contains($e->getMessage(), 'SHA-256 mismatch');
    }
    backupAssert($forgedHashRejected, 'journal accepted forged backup manifest SHA-256');
    backupAssert(($journal->load($transactionId)['state'] ?? '') === 'initialized', 'forged backup metadata changed journal state');

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
