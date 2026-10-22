<?php
declare(strict_types=1);

$root = dirname(__DIR__,2);
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/core/MutationCommandStore.php';
require_once $root . '/modules/tasks/services/TaskBoardAcceptanceService.php';

use Core\DatabaseManager;
use Modules\Tasks\TaskBoardAcceptanceService;

function acceptanceCheck(bool $condition,string $reason): void
{
    if (!$condition) throw new RuntimeException('Принятие задачи: ' . $reason);
}

$name = getenv('TEST_DB_NAME') ?: '';
acceptanceCheck(str_contains(strtolower($name),'test'),'Нужна изолированная тестовая БД');
$db = DatabaseManager::forConnection(new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('TEST_DB_HOST') ?: '127.0.0.1',getenv('TEST_DB_PORT') ?: '3306',$name),
    getenv('TEST_DB_USER') ?: 'workspace',getenv('TEST_DB_PASS') ?: 'workspace',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
));
$ddl = [
    "CREATE TABLE users (id INT PRIMARY KEY,is_active TINYINT NOT NULL DEFAULT 1,
        account_status VARCHAR(20) NOT NULL DEFAULT 'active') ENGINE=InnoDB",
    "CREATE TABLE task_boards (id INT PRIMARY KEY,uid CHAR(32) NOT NULL,
        owner_user_id INT NOT NULL,audience VARCHAR(20) NOT NULL DEFAULT 'members',
        is_archived TINYINT NOT NULL DEFAULT 0,updated_at DATETIME NULL) ENGINE=InnoDB",
    "CREATE TABLE task_board_members (board_id INT NOT NULL,user_id INT NOT NULL,
        role VARCHAR(20) NOT NULL,PRIMARY KEY(board_id,user_id)) ENGINE=InnoDB",
    "CREATE TABLE task_board_items (id INT PRIMARY KEY,uid CHAR(32) NOT NULL,
        board_id INT NOT NULL,status VARCHAR(25) NOT NULL DEFAULT 'pending',
        version INT NOT NULL DEFAULT 1,is_deleted TINYINT NOT NULL DEFAULT 0,
        completed_at DATETIME NULL,archived_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB",
    "CREATE TABLE task_board_assignees (task_id INT NOT NULL,user_id INT NOT NULL,
        PRIMARY KEY(task_id,user_id)) ENGINE=InnoDB",
];
foreach ($ddl as $sql) $db->execute($sql);
try {
    foreach ([
        "INSERT INTO users(id) VALUES (11),(12),(13),(14)",
        "INSERT INTO task_boards(id,uid,owner_user_id) VALUES (1,'" . str_repeat('a',32) . "',11)",
        "INSERT INTO task_board_members(board_id,user_id,role)
           VALUES (1,12,'member'),(1,13,'viewer'),(1,14,'member')",
        "INSERT INTO task_board_items(id,uid,board_id) VALUES (1,'" . str_repeat('b',32) . "',1)",
        "INSERT INTO task_board_assignees(task_id,user_id) VALUES (1,12),(1,13)",
    ] as $sql) $db->execute($sql);
    $migration = file_get_contents($root.'/database/migrations/20261022_tasks_assignee_acceptance.sql');
    acceptanceCheck(is_string($migration),'Отсутствует миграция');
    $migration = preg_replace('/^\s*--[^\n]*\n/m','',$migration) ?? '';
    $db->execute(trim($migration));
    $journal = file_get_contents($root.'/database/migrations/20261017_mutation_commands.sql');
    acceptanceCheck(is_string($journal),'Отсутствует журнал команд');
    $db->execute(substr($journal,strpos($journal,'CREATE TABLE')));

    $service = new TaskBoardAcceptanceService($db);
    $uid = str_repeat('b',32);
    foreach ([[13,403],[14,403]] as [$actor,$expected]) {
        $denied = false;
        try { $service->accept($actor,$uid,1,'accept-denied-'.$actor); }
        catch (DomainException $e) { $denied = $e->getCode()===$expected; }
        acceptanceCheck($denied,'Наблюдатель или неназначенный участник приняли задачу');
    }
    $first = $service->accept(12,$uid,1,'accept-command-0001');
    acceptanceCheck($first['success'] && $first['version'] === 2
        && $first['accepted_by_user_id'] === 12,'Не применено первое принятие');
    $same = $service->accept(12,$uid,1,'accept-command-0001');
    acceptanceCheck($same['replayed'] === true,'Повтор команды создал новый эффект');
    $conflict = $service->accept(12,$uid,1,'accept-command-0002');
    acceptanceCheck($conflict['error'] === 'version_conflict'
        && $conflict['current_version'] === 2,'Устаревшая версия не отклонена');
    acceptanceCheck((int)$db->fetchValue('SELECT version FROM task_board_items WHERE id=1') === 2,
        'Повтор увеличил версию');
    $db->execute('DELETE FROM task_board_assignees WHERE task_id=1 AND user_id=12');
    $revoked = false;
    try { $service->accept(12,$uid,1,'accept-command-0001'); }
    catch (DomainException $e) { $revoked = $e->getCode() === 403; }
    acceptanceCheck($revoked,'Повтор после отзыва назначения разрешён');
    $who = $db->fetchOne('SELECT status,accepted_by_user_id,accepted_at FROM task_board_items WHERE id=1');
    acceptanceCheck($who['status'] === 'in_progress'
        && (int)$who['accepted_by_user_id'] === 12 && $who['accepted_at'] !== null,
        'Не сохранены сведения о принятии');
    acceptanceCheck((int)$db->fetchValue('SELECT COUNT(*) FROM mutation_commands') === 2,
        'Неверное число квитанций');
    echo "Принятие: исполнитель, наблюдатель, повтор, версия и отзыв назначения проверены\n";
} finally {
    foreach (['mutation_commands','task_board_assignees','task_board_items',
              'task_board_members','task_boards','users'] as $table) {
        $db->execute('DROP TABLE IF EXISTS '.$table);
    }
}
