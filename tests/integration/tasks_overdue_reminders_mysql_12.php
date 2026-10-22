<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/DatabaseManager.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationEvent.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationStore.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationService.php';
require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskOverdueReminderPublisher.php';

use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationStore;
use App\Services\Notifications\NotificationService;
use Core\DatabaseManager;
use Modules\Tasks\TaskOverdueReminderPublisher;

class OverdueStore implements NotificationStore
{
    public array $events = [];
    public function persist(NotificationEvent $event): array
    {
        $this->events[$event->idempotencyKey] = $event;
        return ['event_id' => 1, 'notification_ids' => [1]];
    }
}
function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
$name = (string) (getenv('TEST_DB_NAME') ?: '');
verify($name !== '' && str_contains($name, 'test'), 'Нужна отдельная тестовая БД');
$db = DatabaseManager::forConnection(new PDO(
    'mysql:host=' . (getenv('TEST_DB_HOST') ?: '127.0.0.1') . ';dbname=' . $name . ';charset=utf8mb4',
    getenv('TEST_DB_USER') ?: 'workspace', getenv('TEST_DB_PASS') ?: 'workspace',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
));
foreach ([
    "CREATE TABLE users(id INT PRIMARY KEY,is_active TINYINT DEFAULT 1,account_status VARCHAR(20) DEFAULT 'active',deletion_requested_at DATETIME NULL,anonymized_at DATETIME NULL)",
    "CREATE TABLE task_user_preferences(user_id INT PRIMARY KEY,timezone VARCHAR(64))",
    "CREATE TABLE task_morning_digest_settings(user_id INT PRIMARY KEY,enabled TINYINT DEFAULT 0)",
    "CREATE TABLE tasks(id INT PRIMARY KEY,uid CHAR(32),user_id INT,title VARCHAR(255),due_date DATETIME,status VARCHAR(24) DEFAULT 'pending',is_deleted TINYINT DEFAULT 0,archived_at DATETIME NULL)"
] as $sql) $db->execute($sql);
try {
    $db->execute("INSERT INTO users(id) VALUES(11),(12)");
    $db->execute("INSERT INTO task_user_preferences VALUES(11,'Europe/Amsterdam'),(12,'Europe/Amsterdam')");
    $db->execute("INSERT INTO task_morning_digest_settings VALUES(11,1),(12,0)");
    $db->execute("INSERT INTO tasks(id,uid,user_id,title,due_date,status) VALUES
        (1,'11111111111111111111111111111111',11,'Просрочена','2026-10-20 12:00:00','pending'),
        (2,'22222222222222222222222222222222',11,'Завершена','2026-10-20 12:00:00','completed'),
        (3,'33333333333333333333333333333333',12,'Без согласия','2026-10-20 12:00:00','pending'),
        (4,'44444444444444444444444444444444',11,'Сегодня','2026-10-22 12:00:00','pending')");
    $store = new OverdueStore();
    $publisher = new TaskOverdueReminderPublisher($db, new NotificationService($store));
    $instant = new DateTimeImmutable('2026-10-22 07:20:00+00:00');
    verify($publisher->run($instant,1)['published'] === 1, 'Не опубликовано напоминание');
    verify(count($store->events) === 1, 'Повтор или чужой получатель');
    verify($publisher->run($instant,1)['published'] === 1 && count($store->events) === 1,
        'Повторный запуск создал дубликаты ключей');
    verify($publisher->run(new DateTimeImmutable('2026-10-22 10:00:00+00:00'))['published'] === 0,
        'Напоминание опубликовано вне окна');
    $db->execute("UPDATE tasks SET status='completed' WHERE id=1");
    verify($publisher->run(new DateTimeImmutable('2026-10-23 07:20:00+00:00'))['published'] === 0,
        'Завершённая задача продолжает напоминать');
    echo "Напоминания Tasks: календарный день, согласие, завершение и повторобезопасность проверены\n";
} finally {
    foreach (['tasks','task_morning_digest_settings','task_user_preferences','users'] as $name) {
        $db->execute('DROP TABLE IF EXISTS ' . $name);
    }
}
