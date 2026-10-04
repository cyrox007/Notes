<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/UpdateBackupManager.php';
require_once dirname(__DIR__, 2) . '/core/UpdateDatabaseRestorer.php';

function interruptedRestoreDatabase(): mysqli
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli((string) getenv('DBHOST'), (string) getenv('DBUSER'),
        (string) getenv('DBPASS'), (string) getenv('DBNAME'), (int) (getenv('DBPORT') ?: 3306));
    $db->set_charset('utf8mb4');
    return $db;
}

function interruptedRestoreAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// Отдельный процесс использует настоящий восстановитель. В рабочий код
// не добавляются точки остановки или специальные тестовые режимы.
if (($argv[1] ?? '') === '--child') {
    $backupDir = (string) ($argv[2] ?? '');
    $manifest = json_decode((string) file_get_contents($backupDir . '/backup.json'), true, 64, JSON_THROW_ON_ERROR);
    $db = interruptedRestoreDatabase();
    try {
        (new Core\UpdateDatabaseRestorer())->restore($db, $backupDir, $manifest['database']);
        echo "[OK] Отдельный процесс завершил восстановление\n";
    } finally {
        $db->close();
    }
    exit(0);
}

function interruptedRestoreStart(string $backupDir, string $logPath): mixed
{
    $process = proc_open([PHP_BINARY, __FILE__, '--child', $backupDir], [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a'],
    ], $pipes);
    interruptedRestoreAssert(is_resource($process), 'Не удалось запустить отдельный тестовый процесс');
    return $process;
}

$temp = sys_get_temp_dir() . '/updater-interrupted-restore-' . bin2hex(random_bytes(6));
mkdir($temp, 0700);
mkdir($temp . '/live', 0700);
file_put_contents($temp . '/live/index.php', "<?php echo 'source';\n");
$db = interruptedRestoreDatabase();
$process = null;
$lock = 'updater_restore_' . bin2hex(random_bytes(8));
try {
    $db->query('CREATE TABLE audit (id INT PRIMARY KEY, item_id INT NOT NULL) ENGINE=InnoDB');
    $db->query('CREATE TABLE items (id INT PRIMARY KEY, title VARCHAR(100), metadata JSON, payload LONGBLOB) ENGINE=InnoDB');
    $db->query('CREATE TRIGGER items_after_insert AFTER INSERT ON items FOR EACH ROW INSERT INTO audit VALUES (NEW.id, NEW.id)');
    $db->query("INSERT INTO items VALUES (1,'Исходная строка',JSON_OBJECT('kind','source'),X'00FF01'),
        (2,'Вторая строка',JSON_OBJECT('enabled',TRUE),X'FF00FE')");
    $backup = (new Core\UpdateBackupManager($temp . '/backups', $temp . '/live'))->create('snapshot-001', $db);

    // Именованная блокировка детерминированно останавливает SQL-поток после
    // первой уже восстановленной строки. Контрольные суммы тестового дампа
    // обновляются, чтобы не обходить штатную проверку перед удалением таблиц.
    $dumpPath = $backup['backup_dir'] . '/database.sql';
    $dump = (string) file_get_contents($dumpPath);
    $dump = preg_replace('/(^INSERT INTO `items`[^\n]*\n)/m',
        '$1' . "SELECT GET_LOCK('{$lock}',60);\n", $dump, 1, $replaced);
    interruptedRestoreAssert(is_string($dump) && $replaced === 1, 'Не найдена точка прерывания SQL');
    file_put_contents($dumpPath, $dump);
    $manifestPath = $backup['backup_dir'] . '/backup.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
    $manifest['database']['bytes'] = strlen($dump);
    $manifest['database']['sha256'] = hash('sha256', $dump);
    file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
    $db->query("UPDATE items SET title='Изменено после резервирования'");
    $db->query('CREATE TABLE migration_leak (id INT PRIMARY KEY) ENGINE=InnoDB');
    interruptedRestoreAssert((int) $db->query("SELECT GET_LOCK('{$lock}',1) AS acquired")->fetch_assoc()['acquired'] === 1,
        'Не получена контрольная блокировка');

    $process = interruptedRestoreStart($backup['backup_dir'], $temp . '/child.log');
    $deadline = microtime(true) + 15;
    $partial = false;
    while (microtime(true) < $deadline) {
        try {
            $rows = $db->query('SELECT id,title FROM items ORDER BY id')->fetch_all(MYSQLI_ASSOC);
            $partial = count($rows) === 1 && $rows[0]['title'] === 'Исходная строка';
        } catch (mysqli_sql_exception $error) {
            if ($error->getCode() !== 1146) throw $error; // Таблица между DROP и CREATE.
        }
        if ($partial) break;
        interruptedRestoreAssert(proc_get_status($process)['running'], 'Восстановитель завершился до точки прерывания');
        usleep(50000);
    }
    interruptedRestoreAssert($partial, 'Не подтверждено частичное фактическое восстановление БД');
    interruptedRestoreAssert(proc_terminate($process, 9), 'Не удалось принудительно завершить PHP');
    $deadline = microtime(true) + 5;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) break;
        usleep(50000);
    } while (microtime(true) < $deadline);
    interruptedRestoreAssert(!$status['running'] && $status['signaled'] && $status['termsig'] === 9,
        'Не подтверждена смерть PHP по SIGKILL');
    proc_close($process);
    $process = null;
    $db->query("SELECT RELEASE_LOCK('{$lock}')");
    // Дожидаемся освобождения блокировки погибшей SQL-сессией.
    interruptedRestoreAssert((int) $db->query("SELECT GET_LOCK('{$lock}',5) AS acquired")->fetch_assoc()['acquired'] === 1,
        'SQL-сессия погибшего PHP не завершилась');
    $db->query("SELECT RELEASE_LOCK('{$lock}')");

    $process = interruptedRestoreStart($backup['backup_dir'], $temp . '/child.log');
    $exit = proc_close($process);
    $process = null;
    interruptedRestoreAssert($exit === 0, 'Повторный процесс не подтвердил полное восстановление');
    $rows = $db->query('SELECT id,title,JSON_UNQUOTE(JSON_EXTRACT(metadata,\'$.kind\')) AS kind,HEX(payload) AS payload FROM items ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    interruptedRestoreAssert(count($rows) === 2 && $rows[0]['title'] === 'Исходная строка'
        && $rows[0]['kind'] === 'source' && $rows[0]['payload'] === '00FF01'
        && $rows[1]['title'] === 'Вторая строка' && $rows[1]['payload'] === 'FF00FE',
        'Повторный откат не восстановил строки, JSON и BLOB');
    interruptedRestoreAssert((int) $db->query('SELECT COUNT(*) AS c FROM audit')->fetch_assoc()['c'] === 2,
        'После повторного отката остались дубли аудита');
    interruptedRestoreAssert((int) $db->query("SELECT COUNT(*) AS c FROM information_schema.tables
        WHERE table_schema=DATABASE() AND table_name='migration_leak'")->fetch_assoc()['c'] === 0,
        'Таблица неудачной миграции пережила повторный откат');
    $db->query("INSERT INTO items VALUES (3,'После отката',NULL,X'01')");
    interruptedRestoreAssert((int) $db->query('SELECT COUNT(*) AS c FROM audit')->fetch_assoc()['c'] === 3,
        'Восстановленный триггер не работает');
    echo "[OK] SIGKILL посреди SQL-отката: новый процесс восстановил строки, JSON, BLOB и триггер без дублей\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process, 9);
        proc_close($process);
    }
    $db->query("SELECT RELEASE_LOCK('{$lock}')");
    $db->query('DROP TABLE IF EXISTS items,audit,migration_leak');
    $db->close();
    Core\UpdatePath::removeTree($temp);
}
