<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/DatabaseManager.php';
require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskPersonalQueue.php';
require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskAssigneeQueueService.php';
require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskMorningDigestPublisher.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationStore.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationEvent.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationService.php';

use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\NotificationStore;
use Core\DatabaseManager;
use Modules\Tasks\TaskMorningDigestPublisher;

final class DigestRecordingStore implements NotificationStore
{
    /** @var array<string,NotificationEvent> */
    public array $events = [];

    public function persist(NotificationEvent $event): array
    {
        $this->events[$event->idempotencyKey] = $event;
        return ['event_id' => count($this->events), 'notification_ids' => [1]];
    }
}

function digestAssert(bool $valid, string $description): void
{
    if (!$valid) {
        throw new RuntimeException('Сводки Tasks: ' . $description);
    }
}

$name = (string) (getenv('TEST_DB_NAME') ?: '');
digestAssert($name !== '' && str_contains(strtolower($name), 'test'), 'нужна изолированная тестовая БД');
$db = DatabaseManager::forConnection(new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('TEST_DB_HOST') ?: '127.0.0.1',
        getenv('TEST_DB_PORT') ?: '3306',
        $name),
    getenv('TEST_DB_USER') ?: 'workspace',
    getenv('TEST_DB_PASS') ?: 'workspace',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
));
$tables = [
    "CREATE TABLE users(id INT PRIMARY KEY,is_active TINYINT DEFAULT 1,account_status VARCHAR(20) DEFAULT 'active',
      deletion_requested_at DATETIME NULL,anonymized_at DATETIME NULL)",
    "CREATE TABLE tasks(id INT PRIMARY KEY,uid CHAR(32),user_id INT,title VARCHAR(255),
      status VARCHAR(24) DEFAULT 'pending',priority VARCHAR(24) DEFAULT 'medium',
      due_date DATETIME,version INT DEFAULT 1,is_deleted TINYINT DEFAULT 0,archived_at DATETIME NULL)",
    "CREATE TABLE task_boards(id INT PRIMARY KEY,uid CHAR(32),name VARCHAR(160),
      owner_user_id INT,audience VARCHAR(20) DEFAULT 'members',is_archived TINYINT DEFAULT 0)",
    "CREATE TABLE task_board_members(board_id INT,user_id INT,role VARCHAR(20),PRIMARY KEY(board_id,user_id))",
    "CREATE TABLE task_board_items(id INT PRIMARY KEY,uid CHAR(32),board_id INT,
      title VARCHAR(255),status VARCHAR(24) DEFAULT 'pending',priority VARCHAR(24) DEFAULT 'medium',
      due_date DATETIME,version INT DEFAULT 1,is_deleted TINYINT DEFAULT 0,archived_at DATETIME NULL)",
    "CREATE TABLE task_board_assignees(task_id INT,user_id INT,PRIMARY KEY(task_id,user_id))",
    "CREATE TABLE task_user_preferences(user_id INT PRIMARY KEY,timezone VARCHAR(64) NOT NULL)",
    "CREATE TABLE task_morning_digest_settings(user_id INT PRIMARY KEY,enabled TINYINT NOT NULL DEFAULT 0)",
];
foreach ($tables as $sql) {
    $db->execute($sql);
}
try {
    $db->execute("INSERT INTO users(id) VALUES (11),(12),(13)");
    $db->execute("INSERT INTO task_user_preferences(user_id,timezone)
      VALUES (11,'Europe/Amsterdam'),(12,'Europe/Amsterdam'),(13,'Asia/Vladivostok')");
    $db->execute("INSERT INTO task_morning_digest_settings(user_id,enabled)
      VALUES (11,1),(12,0),(13,1)");
    $db->execute("INSERT INTO tasks(id,uid,user_id,title,due_date) VALUES
      (1,'11111111111111111111111111111111',11,'Сегодня','2026-10-22 14:00:00'),
      (2,'22222222222222222222222222222222',12,'Без согласия','2026-10-22 13:00:00'),
      (3,'33333333333333333333333333333333',13,'Другая зона','2026-10-23 12:00:00')");
    $store = new DigestRecordingStore();
    $worker = new TaskMorningDigestPublisher($db, new NotificationService($store));
    $morning = new DateTimeImmutable('2026-10-22 06:15:00', new DateTimeZone('UTC'));
    $first = $worker->run($morning);
    $second = $worker->run($morning);
    digestAssert($first['published'] === 1 && $second['published'] === 1,
        'должен обрабатываться только согласившийся адресат в своей утренней зоне');
    digestAssert(count($store->events) === 1, 'ключ не обеспечивает повторобезопасность');
    $notice = array_values($store->events)[0];
    digestAssert($notice->recipientUserIds === [11], 'утечка адресата без согласия');
    digestAssert($notice->payload['summary']['today'] === 1, 'неверная сводка');
    digestAssert($worker->run(new DateTimeImmutable('2026-10-22 10:00:00+00:00'))['published'] === 0,
        'рассылка вне утреннего окна');
    $db->execute('UPDATE task_morning_digest_settings SET enabled=0 WHERE user_id=11');
    digestAssert($worker->run($morning)['published'] === 0, 'отключение согласия не действует');
    echo "Сводки Tasks: согласие, временное окно, уникальный ключ и точные итоги проверены\n";
} finally {
    foreach (['task_morning_digest_settings','task_user_preferences','task_board_assignees',
      'task_board_items','task_board_members','task_boards','tasks','users'] as $table) {
        $db->execute('DROP TABLE IF EXISTS ' . $table);
    }
}
