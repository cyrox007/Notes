<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/core/DatabaseManager.php';
require_once dirname(__DIR__,2).'/modules/tasks/services/TaskPersonalQueue.php';
require_once dirname(__DIR__,2).'/modules/tasks/services/TaskAssigneeQueueService.php';
require_once dirname(__DIR__,2).'/modules/tasks/services/TaskUserTimezoneService.php';

use Core\DatabaseManager;
use Modules\Tasks\TaskAssigneeQueueService;
use Modules\Tasks\TaskUserTimezoneService;

function checkQueue(bool $ok,string $reason): void {
    if (!$ok) throw new RuntimeException('Общая очередь: '.$reason);
}
$name = getenv('TEST_DB_NAME') ?: '';
checkQueue(str_contains(strtolower($name),'test'),'требуется изолированная БД');
$db = DatabaseManager::forConnection(new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('TEST_DB_HOST') ?: '127.0.0.1',
        getenv('TEST_DB_PORT') ?: '3306', $name),
    getenv('TEST_DB_USER') ?: 'workspace',getenv('TEST_DB_PASS') ?: 'workspace',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
));
foreach ([
    "CREATE TABLE users(id INT PRIMARY KEY,is_active TINYINT DEFAULT 1,account_status VARCHAR(20) DEFAULT 'active')",
    "CREATE TABLE tasks(id INT PRIMARY KEY,uid CHAR(32),user_id INT,title VARCHAR(255),
        status VARCHAR(24) DEFAULT 'pending',priority VARCHAR(24) DEFAULT 'medium',
        due_date DATETIME,version INT DEFAULT 1,is_deleted TINYINT DEFAULT 0,archived_at DATETIME NULL)",
    "CREATE TABLE task_boards(id INT PRIMARY KEY,uid CHAR(32),name VARCHAR(160),
        owner_user_id INT,audience VARCHAR(20) DEFAULT 'members',is_archived TINYINT DEFAULT 0)",
    "CREATE TABLE task_board_members(board_id INT,user_id INT,role VARCHAR(20),
        PRIMARY KEY(board_id,user_id))",
    "CREATE TABLE task_board_items(id INT PRIMARY KEY,uid CHAR(32),board_id INT,
        title VARCHAR(255),status VARCHAR(24) DEFAULT 'pending',
        priority VARCHAR(24) DEFAULT 'medium',due_date DATETIME,
        version INT DEFAULT 1,is_deleted TINYINT DEFAULT 0,archived_at DATETIME NULL)",
    "CREATE TABLE task_board_assignees(task_id INT,user_id INT,PRIMARY KEY(task_id,user_id))",
    "CREATE TABLE task_user_preferences(user_id INT PRIMARY KEY,timezone VARCHAR(64) NOT NULL)",
] as $sql) $db->execute($sql);
try {
    $db->execute("INSERT INTO users(id) VALUES(11),(12),(13)");
    $db->execute("INSERT INTO task_boards(id,uid,name,owner_user_id)
        VALUES(1,'".str_repeat('a',32)."','Команда',12),
        (2,'".str_repeat('b',32)."','Чужая доска',13)");
    $db->execute("INSERT INTO task_board_members(board_id,user_id,role) VALUES(1,11,'member')");
    $db->execute("INSERT INTO tasks(id,uid,user_id,title,due_date) VALUES
        (1,'".str_repeat('1',32)."',11,'Личная вчера','2026-10-21 10:00:00'),
        (2,'".str_repeat('2',32)."',11,'Личная сегодня','2026-10-22 18:00:00'),
        (3,'".str_repeat('3',32)."',12,'Чужая личная','2026-10-22 11:00:00')");
    $db->execute("INSERT INTO task_board_items(id,uid,board_id,title,due_date) VALUES
        (1,'".str_repeat('4',32)."',1,'Командная завтра','2026-10-23 09:00:00'),
        (2,'".str_repeat('5',32)."',2,'Без доступа','2026-10-22 09:00:00'),
        (3,'".str_repeat('6',32)."',1,'Командная сегодня','2026-10-22 09:00:00')");
    $db->execute("INSERT INTO task_board_assignees(task_id,user_id) VALUES(1,11),(2,11),(3,11)");
    $now = new DateTimeImmutable('2026-10-22 12:00:00',new DateTimeZone('Europe/Amsterdam'));
    $queue = (new TaskAssigneeQueueService($db))->forUser(11,$now);
    checkQueue($queue['summary']['overdue'] === 1 && $queue['summary']['today'] === 2
        && $queue['summary']['tomorrow'] === 1 && $queue['summary']['total'] === 4,
        'нет точной ежедневной сводки');
    $preferences = new TaskUserTimezoneService($db);
    checkQueue($preferences->forUser(11)->getName() === date_default_timezone_get(),
        'не работает зона установки по умолчанию');
    checkQueue($preferences->save(11,'Europe/Amsterdam') === 'Europe/Amsterdam',
        'пользователь не может сохранить зону');
    checkQueue($preferences->save(12,'Asia/Vladivostok') === 'Asia/Vladivostok',
        'зоны разных пользователей смешаны');
    checkQueue($preferences->forUser(11)->getName() === 'Europe/Amsterdam',
        'при повторном чтении личная зона потеряна');
    $instant = new DateTimeImmutable('2026-10-22 22:30:00', new DateTimeZone('UTC'));
    checkQueue($preferences->nowFor(12,$instant)->format('Y-m-d') === '2026-10-23'
        && $preferences->nowFor(11,$instant)->format('Y-m-d') === '2026-10-23',
        'смена местного календарного дня не учтена');
    checkQueue($preferences->nowFor(11,new DateTimeImmutable('2026-03-29 01:30:00', new DateTimeZone('UTC')))
        ->format('H:i') === '03:30', 'переход на летнее время не учтён');
    $invalid = false;
    try { $preferences->save(11,'../invalid'); } catch (InvalidArgumentException) { $invalid = true; }
    checkQueue($invalid,'можно установить произвольную или небезопасную зону');
    $overdue = array_column($queue['groups']['overdue'],'title');
    $today = array_column($queue['groups']['today'],'title');
    $tomorrow = array_column($queue['groups']['tomorrow'],'title');
    checkQueue($overdue === ['Личная вчера'],'неверно определено просроченное');
    checkQueue($today === ['Командная сегодня','Личная сегодня'],'ошибка объединённого дня');
    checkQueue($tomorrow === ['Командная завтра'],'неверно определено завтра');
    checkQueue($queue['timezone'] === 'Europe/Amsterdam','потерян часовой пояс');
    $db->execute("UPDATE task_board_members SET role='viewer' WHERE user_id=11");
    checkQueue(count((new TaskAssigneeQueueService($db))->forUser(11,$now)['groups']['tomorrow']) === 1,
        'наблюдателю скрыли назначенную задачу');
    $db->execute('DELETE FROM task_board_members WHERE user_id=11');
    checkQueue((new TaskAssigneeQueueService($db))->forUser(11,$now)['groups']['tomorrow'] === [],
        'отозванный доступ к доске не учитывается');
    for ($index=100; $index<135; ++$index) {
        $db->execute(
            "INSERT INTO tasks(id,uid,user_id,title,due_date)
             VALUES(:id,:uid,11,'Дополнительная задача','2026-10-22 19:00:00')",
            [':id'=>$index, ':uid'=>str_pad((string)$index,32,'0',STR_PAD_LEFT)]
        );
    }
    $large = (new TaskAssigneeQueueService($db))->forUser(11,$now);
    checkQueue($large['summary']['today'] === 36, 'сводка обрезана по размеру списка');
    checkQueue(count($large['groups']['today']) === 30 && $large['has_more']['today'],
        'лимит карточек должен быть независим от точного итога');
    echo "Единая очередь: сроки, назначение, ACL, зоны и полные сводки проверены\n";
} finally {
    foreach (['task_user_preferences','task_board_assignees','task_board_items','task_board_members',
              'task_boards','tasks','users'] as $table) $db->execute('DROP TABLE IF EXISTS '.$table);
}
