<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'Core\\')) {
        return;
    }
    $path = $root . '/core/' . substr($class, 5) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require_once $root . '/modules/tasks/services/TaskVersionMutationService.php';

use App\Services\Tasks\TaskVersionMutationService;
use Core\DatabaseOwnership;
use Core\InstallerDatabaseService;
use Core\MutationCommandStore;
use InvalidArgumentException;

function integrityCheck(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "Контракт целостности 1.2 нарушен: {$message}\n");
    exit(1);
}

$database = getenv('DATA_INTEGRITY_DBNAME');
integrityCheck(is_string($database) && preg_match('/^integrity_[a-z0-9_]+$/D', $database) === 1, 'нужна изолированная DATA_INTEGRITY_DBNAME');
$host = (string) (getenv('DBHOST') ?: '127.0.0.1');
$port = (int) (getenv('DBPORT') ?: 3306);
$user = (string) (getenv('DBUSER') ?: 'root');
$password = (string) (getenv('DBPASS') ?: '');
$prefix = (string) (getenv('DBPREFIX') ?: '');
putenv('DBNAME=' . $database);
putenv('DBPREFIX=' . $prefix);

$ownership = DatabaseOwnership::fromPackageRoot($root);
integrityCheck(in_array('mutation_commands', $ownership->tables(), true), 'Core должен владеть mutation_commands');
integrityCheck(in_array('database/migrations/20261017_mutation_commands.sql', $ownership->migrationFiles(), true), 'Core-миграция команд должна принадлежать пакету');
integrityCheck(in_array('database/migrations/20261017_tasks_entity_versions.sql', $ownership->migrationFiles(), true), 'Tasks должен владеть миграцией версий');

$schemas = array_map(static fn (string $path): string => $root . '/' . $path, $ownership->schemaFiles());
$installer = new InstallerDatabaseService();
$installer->importSchemas($host, $port, $database, $user, $password, $schemas);
$pdo = $installer->connect($host, $port, $database, $user, $password);
$installer->createAdminUser($pdo, 'integrity-admin', 'integrity@example.test', 'integrity-test-password', 'Integrity', 'Admin');
$userId = (int) $pdo->query("SELECT id FROM users WHERE username='integrity-admin' LIMIT 1")->fetchColumn();
integrityCheck($userId > 0, 'тестовый пользователь не создан');

$taskUid = bin2hex(random_bytes(16));
$insert = $pdo->prepare(
    'INSERT INTO tasks (uid,user_id,title,description,status,priority,version,created_at,updated_at)
     VALUES (:uid,:user_id,:title,NULL,\'pending\',\'medium\',1,NOW(),NOW())'
);
$insert->execute([':uid' => $taskUid, ':user_id' => $userId, ':title' => 'Исходная задача']);

$service = new TaskVersionMutationService($pdo, new MutationCommandStore());
$first = $service->mutateOwned(
    $userId,
    $taskUid,
    1,
    'tab-a-command-0001',
    'task.update',
    ['title' => 'Изменение из вкладки A']
);
integrityCheck(($first['success'] ?? false) === true, 'первая команда должна примениться');
integrityCheck((int) ($first['version'] ?? 0) === 2, 'первая команда должна поднять version до 2');
integrityCheck(($first['replayed'] ?? true) === false, 'первое выполнение не должно считаться повтором');

$stale = $service->mutateOwned(
    $userId,
    $taskUid,
    1,
    'tab-b-command-0001',
    'task.update',
    ['title' => 'Устаревшее изменение из вкладки B']
);
integrityCheck(($stale['success'] ?? true) === false, 'устаревшая команда должна вернуть конфликт');
integrityCheck(($stale['error'] ?? '') === 'version_conflict', 'конфликт должен иметь стабильный код version_conflict');
integrityCheck((int) ($stale['current_version'] ?? 0) === 2, 'конфликт должен вернуть актуальную версию');
integrityCheck(($stale['current']['title'] ?? '') === 'Изменение из вкладки A', 'конфликт должен вернуть актуальное состояние');

$replayed = $service->mutateOwned(
    $userId,
    $taskUid,
    1,
    'tab-a-command-0001',
    'task.update',
    ['title' => 'Изменение из вкладки A']
);
integrityCheck(($replayed['success'] ?? false) === true, 'повтор применённой команды должен вернуть успех');
integrityCheck(($replayed['replayed'] ?? false) === true, 'повтор должен быть явно помечен');
integrityCheck((int) ($replayed['version'] ?? 0) === 2, 'повтор не должен увеличивать version повторно');

$conflictReplay = $service->mutateOwned(
    $userId,
    $taskUid,
    1,
    'tab-b-command-0001',
    'task.update',
    ['title' => 'Устаревшее изменение из вкладки B']
);
integrityCheck(($conflictReplay['error'] ?? '') === 'version_conflict', 'повтор конфликта должен быть детерминированным');
integrityCheck(($conflictReplay['replayed'] ?? false) === true, 'повтор конфликта должен возвращаться из receipt');

$reusedWithDifferentPayload = false;
try {
    $service->mutateOwned(
        $userId,
        $taskUid,
        1,
        'tab-a-command-0001',
        'task.update',
        ['title' => 'Другой payload под старым command_id']
    );
} catch (InvalidArgumentException) {
    $reusedWithDifferentPayload = true;
}
integrityCheck($reusedWithDifferentPayload, 'один command_id нельзя повторно использовать с другим payload');

$second = $service->mutateOwned(
    $userId,
    $taskUid,
    2,
    'tab-a-command-0002',
    'task.update',
    ['priority' => 'high']
);
integrityCheck(($second['success'] ?? false) === true && (int) ($second['version'] ?? 0) === 3, 'следующая актуальная команда должна примениться как version 3');

$current = $pdo->query("SELECT title,priority,version FROM tasks WHERE uid='" . $taskUid . "' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
integrityCheck(is_array($current), 'задача должна сохраниться');
integrityCheck(($current['title'] ?? '') === 'Изменение из вкладки A', 'устаревшая вкладка не должна перетереть title');
integrityCheck(($current['priority'] ?? '') === 'high', 'актуальная следующая команда должна изменить priority');
integrityCheck((int) ($current['version'] ?? 0) === 3, 'итоговая версия должна быть 3');

$receiptCount = (int) $pdo->query('SELECT COUNT(*) FROM mutation_commands')->fetchColumn();
integrityCheck($receiptCount === 3, 'должен храниться один receipt на логическую команду');
$processingCount = (int) $pdo->query("SELECT COUNT(*) FROM mutation_commands WHERE status='processing'")->fetchColumn();
integrityCheck($processingCount === 0, 'после завершения теста не должно оставаться processing-команд');

$boardVersionColumn = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='"
    . $pdo->namespace->name('task_board_items') . "' AND COLUMN_NAME='version'"
)->fetchColumn();
integrityCheck($boardVersionColumn === 1, 'общие задачи также должны иметь version');

require __DIR__ . '/board_integrity_12_scenarios.php';
require __DIR__ . '/task_relations_12_scenarios.php';

echo 'Контракт целостности данных 1.2 выполнен' . PHP_EOL;
