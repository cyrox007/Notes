<?php

declare(strict_types=1);

// Реальные запросы модулей через менеджер ядра на отдельной тестовой БД.
$root = dirname(__DIR__, 2);
foreach ([
    '/core/DatabaseManager.php',
    '/core/AccountDeactivationGuard.php',
    '/core/ProfileContentProvider.php',
    '/core/WorkspaceTaskCreator.php',
    '/modules/tasks/services/TasksAccountOwnership.php',
    '/modules/tasks/TasksCapability.php',
    '/modules/messenger/services/MessengerAccountOwnership.php',
    '/modules/messenger/MessengerCapability.php',
] as $file) {
    require_once $root . $file;
}

use Core\DatabaseManager;
use Modules\Messenger\MessengerCapability;
use Modules\Tasks\TasksCapability;

function assertBlocker(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('Для теста требуется расширение pdo_sqlite');
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE task_boards (id INTEGER PRIMARY KEY,owner_user_id INT,name TEXT,audience TEXT)');
$pdo->exec('CREATE TABLE task_board_members (board_id INT,user_id INT)');
$pdo->exec('CREATE TABLE dialogs (id INTEGER PRIMARY KEY,type TEXT,name TEXT)');
$pdo->exec('CREATE TABLE user_to_dialogs (dialog_id INT,user_id INT,role TEXT,is_deleted INT)');

$db = DatabaseManager::forConnection($pdo);
$tasks = new TasksCapability($db);
$messenger = new MessengerCapability($db);

assertBlocker($tasks->accountDeactivationBlocker(17) === null, 'Не должно быть ложной блокировки Tasks');
assertBlocker($messenger->accountDeactivationBlocker(17) === null, 'Не должно быть ложной блокировки Messenger');

$pdo->exec("INSERT INTO task_boards(id,owner_user_id,name,audience)
            VALUES (1,17,'Личная доска','private')");
assertBlocker($tasks->accountDeactivationBlocker(17) === null,
    'Личная доска без участников не препятствует деактивации');

$pdo->exec('INSERT INTO task_board_members(board_id,user_id) VALUES (1,23)');
$blocker = $tasks->accountDeactivationBlocker(17);
assertBlocker($blocker !== null && $blocker['code'] === 'task_board_owner_transfer_required',
    'Общая доска требует передачи владельца');

$pdo->exec('DELETE FROM task_board_members');
$pdo->exec("UPDATE task_boards SET audience='all_active' WHERE id=1");
assertBlocker($tasks->accountDeactivationBlocker(17) !== null,
    'Общедоступная доска также требует передачи владельца');

$pdo->exec("INSERT INTO dialogs(id,type,name) VALUES (4,'group','Рабочая группа')");
$pdo->exec("INSERT INTO user_to_dialogs(dialog_id,user_id,role,is_deleted) VALUES (4,17,'owner',0)");
assertBlocker($messenger->accountDeactivationBlocker(17) !== null,
    'Владелец группы Messenger должен быть защищён');

$pdo->exec('UPDATE user_to_dialogs SET is_deleted=1 WHERE dialog_id=4');
assertBlocker($messenger->accountDeactivationBlocker(17) === null,
    'Удалённое членство не создаёт ложную блокировку');

echo "Проверка владельцев Messenger и Tasks на изолированной БД пройдена\n";
