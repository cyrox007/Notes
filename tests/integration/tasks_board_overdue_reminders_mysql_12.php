<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/DatabaseManager.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationEvent.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationStore.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationService.php';
require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskBoardOverdueReminderPublisher.php';

use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationStore;
use App\Services\Notifications\NotificationService;
use Core\DatabaseManager;
use Modules\Tasks\TaskBoardOverdueReminderPublisher;

final class BoardReminderStore implements NotificationStore
{
    public array $events = [];
    public function persist(NotificationEvent $event): array
    {
        $this->events[$event->idempotencyKey] = $event;
        return ['event_id' => count($this->events), 'notification_ids' => [1]];
    }
}
function boardCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('Напоминания досок: ' . $message);
}
$name = (string) (getenv('TEST_DB_NAME') ?: '');
boardCheck($name !== '' && str_contains(strtolower($name), 'test'), 'нужна отдельная тестовая база');
$db = DatabaseManager::forConnection(new PDO(
    'mysql:host=' . (getenv('TEST_DB_HOST') ?: '127.0.0.1')
        . ';dbname=' . $name . ';charset=utf8mb4',
    getenv('TEST_DB_USER') ?: 'workspace', getenv('TEST_DB_PASS') ?: 'workspace',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
));
foreach ([
    "CREATE TABLE users(id INT PRIMARY KEY,is_active TINYINT DEFAULT 1,account_status VARCHAR(20) DEFAULT 'active',deletion_requested_at DATETIME NULL,anonymized_at DATETIME NULL)",
    "CREATE TABLE task_user_preferences(user_id INT PRIMARY KEY,timezone VARCHAR(64))",
    "CREATE TABLE task_morning_digest_settings(user_id INT PRIMARY KEY,enabled TINYINT,repeat_interval_days TINYINT UNSIGNED NULL DEFAULT NULL)",
    "CREATE TABLE task_boards(id INT PRIMARY KEY,uid CHAR(32),owner_user_id INT,name VARCHAR(160),audience VARCHAR(32) DEFAULT 'members',is_archived TINYINT DEFAULT 0)",
    "CREATE TABLE task_board_members(board_id INT,user_id INT,role VARCHAR(32),PRIMARY KEY(board_id,user_id))",
    "CREATE TABLE task_board_items(id INT PRIMARY KEY,uid CHAR(32),board_id INT,status VARCHAR(32) DEFAULT 'pending',due_date DATETIME,is_deleted TINYINT DEFAULT 0,archived_at DATETIME NULL)",
    "CREATE TABLE task_board_assignees(task_id INT,user_id INT,PRIMARY KEY(task_id,user_id))"
] as $sql) $db->execute($sql);
try {
    $db->execute("INSERT INTO users(id) VALUES (11),(12)");
    $db->execute("INSERT INTO task_user_preferences VALUES (11,'Europe/Amsterdam'),(12,'Europe/Amsterdam')");
    $db->execute("INSERT INTO task_morning_digest_settings VALUES (11,1),(12,0)");
    $db->execute("INSERT INTO task_boards(id,uid,owner_user_id,name) VALUES (1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',12,'Доска')");
    $db->execute("INSERT INTO task_board_members VALUES (1,11,'member')");
    $db->execute("INSERT INTO task_board_items(id,uid,board_id,due_date) VALUES (1,'11111111111111111111111111111111',1,'2026-10-20 10:00:00')");
    $db->execute("INSERT INTO task_board_assignees VALUES (1,11),(1,12)");
    $store = new BoardReminderStore();
    $publisher = new TaskBoardOverdueReminderPublisher($db, new NotificationService($store));
    $instant = new DateTimeImmutable('2026-10-22 07:20:00+00:00');
    boardCheck($publisher->run($instant,1)['published'] === 1, 'не отправлено назначенному участнику');
    boardCheck($publisher->run($instant,1)['published'] === 1 && count($store->events) === 1,
        'повтор создаёт лишние события');
    $db->execute('DELETE FROM task_board_members WHERE user_id=11');
    boardCheck($publisher->run(new DateTimeImmutable('2026-10-23 07:20:00+00:00'),1)['published'] === 0,
        'отзыв доступа не исключил адресата');
    $db->execute("INSERT INTO task_board_members VALUES (1,11,'viewer')");
    $db->execute('DELETE FROM task_board_assignees WHERE user_id=11');
    boardCheck($publisher->run(new DateTimeImmutable('2026-10-24 07:20:00+00:00'),1)['published'] === 0,
        'отзыв назначения не исключил адресата');
    $db->execute('INSERT INTO task_board_assignees VALUES (1,11)');
    $db->execute('UPDATE task_board_items SET status=\'completed\' WHERE id=1');
    boardCheck($publisher->run(new DateTimeImmutable('2026-10-25 08:20:00+00:00'),1)['published'] === 0,
        'завершённая задача получила уведомление');
    echo "Командные напоминания: ACL, назначения, согласие и повторы проверены\n";
} finally {
    foreach (['task_board_assignees','task_board_items','task_board_members','task_boards',
        'task_morning_digest_settings','task_user_preferences','users'] as $table) {
        $db->execute('DROP TABLE IF EXISTS ' . $table);
    }
}
