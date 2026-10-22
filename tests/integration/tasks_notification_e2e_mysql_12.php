<?php
declare(strict_types=1);

use App\Services\Notifications\DatabaseNotificationDeliveryQueue;
use App\Services\Notifications\DatabaseNotificationStore;
use App\Services\Notifications\NotificationDeliveryChannel;
use App\Services\Notifications\NotificationDeliveryResult;
use App\Services\Notifications\NotificationDeliveryWorker;
use App\Services\Notifications\NotificationService;
use Core\DatabaseManager;
use Modules\Tasks\TaskOverdueReminderPublisher;
use Modules\Tasks\TaskReminderCycle;

$root = dirname(__DIR__, 2);
foreach ([
    '/core/DatabaseManager.php',
    '/app/services/notifications/NotificationEvent.php',
    '/app/services/notifications/DatabaseNotificationStore.php',
    '/app/services/notifications/NotificationService.php',
    '/app/services/notifications/NotificationDeliveryWorker.php',
    '/modules/tasks/services/TaskOverdueReminderPublisher.php',
    '/modules/tasks/services/TaskReminderCycle.php',
] as $file) {
    require_once $root . $file;
}

function acceptanceCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('Приёмка Tasks: ' . $message);
}

function acceptanceSchema(PDO $pdo, string $path): void
{
    $source = file_get_contents($path);
    acceptanceCheck(is_string($source), 'не найдена схема ' . basename($path));
    foreach (preg_split('/;\s*(?:\R|$)/', $source) ?: [] as $sql) {
        if (trim($sql) !== '') $pdo->exec($sql);
    }
}

final class AcceptanceChannel implements NotificationDeliveryChannel
{
    public int $calls = 0;

    public function name(): string { return 'email'; }

    public function deliver(array $job): NotificationDeliveryResult
    {
        ++$this->calls;
        acceptanceCheck(($job['event_name'] ?? '') === 'tasks.overdue_repeat',
            'получено чужое событие');
        acceptanceCheck(($job['category'] ?? '') === 'tasks.reminders',
            'неверная категория');
        acceptanceCheck(($job['recipient_ref'] ?? '') === 'user:11',
            'чужой получатель');
        if ((int) ($job['attempt_no'] ?? 0) === 1) {
            return NotificationDeliveryResult::failed('transport_temporary', null, true);
        }
        // Это подтверждение тестового адаптера, не реального почтового ящика.
        return NotificationDeliveryResult::sent('transport_accepted');
    }
}

$name = (string) (getenv('TEST_DB_NAME') ?: '');
acceptanceCheck($name !== '' && str_contains(strtolower($name), 'test'),
    'разрешена только отдельная тестовая база');
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('TEST_DB_HOST') ?: '127.0.0.1',
    getenv('TEST_DB_PORT') ?: '3306', $name);
$user = getenv('TEST_DB_USER') ?: 'workspace';
$password = getenv('TEST_DB_PASS') ?: 'workspace';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$pdo = new PDO($dsn, $user, $password, $options);
acceptanceSchema($pdo, $root . '/database/core_identity_schema.sql');
acceptanceSchema($pdo, $root . '/database/notifications_schema.sql');
acceptanceSchema($pdo, $root . '/database/migrations/20261022_tasks_reminder_cycle_runs.sql');
foreach ([
    'CREATE TABLE task_user_preferences (user_id INT PRIMARY KEY,timezone VARCHAR(64) NOT NULL)',
    'CREATE TABLE task_morning_digest_settings (user_id INT PRIMARY KEY,enabled TINYINT NOT NULL DEFAULT 0)',
    "CREATE TABLE tasks (id INT PRIMARY KEY,uid CHAR(32) NOT NULL,user_id INT NOT NULL,
      title VARCHAR(255) NOT NULL,status VARCHAR(24) NOT NULL DEFAULT 'pending',
      due_date DATETIME NULL,is_deleted TINYINT NOT NULL DEFAULT 0,archived_at DATETIME NULL)",
] as $sql) $pdo->exec($sql);

try {
    $pdo->exec("INSERT INTO users(id,uid,username,email,password_hash,firstname,lastname)
      VALUES (11,'00000000-0000-4000-8000-000000000011','e2e-one','one@example.test','hash','Тест','Один'),
             (12,'00000000-0000-4000-8000-000000000012','e2e-two','two@example.test','hash','Тест','Два')");
    $pdo->exec("INSERT INTO task_user_preferences VALUES (11,'Europe/Amsterdam'),(12,'Europe/Amsterdam')");
    $pdo->exec('INSERT INTO task_morning_digest_settings VALUES (11,1),(12,0)');
    $pdo->exec("INSERT INTO tasks (id,uid,user_id,title,due_date)
      VALUES (1,'11111111111111111111111111111111',11,'Просроченная личная','2026-10-20 12:00:00'),
             (2,'22222222222222222222222222222222',12,'Без согласия','2026-10-20 12:00:00')");
    $pdo->exec("INSERT INTO notification_channel_configs
      (channel,owner_user_id,owner_scope,label,enabled,config_json,secret_ref)
      VALUES ('email',NULL,0,'Тестовый транспорт',1,JSON_OBJECT(),'env:TEST_FAKE_TRANSPORT')");
    $pdo->exec("INSERT INTO notification_subscriptions
      (user_id,category,enabled,in_app_enabled,email_enabled)
      VALUES (11,'tasks.reminders',1,1,1),(12,'tasks.reminders',1,1,1)");

    $db = DatabaseManager::forConnection($pdo);
    $publisher = new TaskOverdueReminderPublisher(
        $db, new NotificationService(new DatabaseNotificationStore($db)));
    $transport = new AcceptanceChannel();
    $queue = new DatabaseNotificationDeliveryQueue($db);
    $worker = new NotificationDeliveryWorker($queue, [$transport]);
    $cycle = new TaskReminderCycle($db);
    $morning = new DateTimeImmutable('2026-10-22 07:20:00+00:00');

    $first = $cycle->run(
        static fn(): array => [],
        static fn(): array => $publisher->run($morning, 1),
        static fn(): array => [],
        static fn(): array => $worker->run('tasks-e2e-first', 10)
    );
    acceptanceCheck(($first['status'] ?? '') === 'ok'
        && ($first['personal_published'] ?? 0) === 1
        && ($first['delivery_claimed'] ?? 0) === 1
        && ($first['delivery_retry'] ?? 0) === 1,
        'не выполнена публикация и попытка доставки');

    $sqlCounts = "SELECT
      (SELECT COUNT(*) FROM notification_events) AS events,
      (SELECT COUNT(*) FROM notifications) AS notices,
      (SELECT COUNT(*) FROM notification_delivery_jobs) AS jobs,
      (SELECT COUNT(*) FROM notification_delivery_attempts) AS attempts";
    $counts = $db->fetchOne($sqlCounts);
    acceptanceCheck((int) $counts['events'] === 1 && (int) $counts['notices'] === 1
        && (int) $counts['jobs'] === 1 && (int) $counts['attempts'] === 1,
        'нарушено создание ровно одного комплекта записей');
    $job = $db->fetchOne('SELECT id,state,attempts,locked_by,last_result_code
      FROM notification_delivery_jobs LIMIT 1');
    acceptanceCheck($job['state'] === 'retry' && (int) $job['attempts'] === 1
        && $job['locked_by'] === null && $job['last_result_code'] === 'transport_temporary',
        'временный отказ не сохранён');

    // Идемпотентная публикация того же местного дня.
    acceptanceCheck($publisher->run($morning, 1)['published'] === 1
        && $db->fetchOne($sqlCounts) === $counts, 'повтор размножил уведомления');
    $db->execute("UPDATE notification_delivery_jobs SET next_attempt_at='2000-01-01 00:00:00'");

    // Новый процесс с новым подключением к БД принимает очередную попытку.
    unset($worker, $queue, $publisher, $cycle);
    $reopened = DatabaseManager::forConnection(new PDO($dsn, $user, $password, $options));
    $transportAfterRestart = new AcceptanceChannel();
    $workerAfterRestart = new NotificationDeliveryWorker(
        new DatabaseNotificationDeliveryQueue($reopened), [$transportAfterRestart]);
    $done = $workerAfterRestart->run('tasks-e2e-restarted', 10);
    acceptanceCheck($done === ['claimed' => 1, 'sent' => 1, 'retry' => 0, 'failed' => 0]
        && $transportAfterRestart->calls === 1, 'повтор после перезапуска не завершён');
    $sent = $reopened->fetchOne('SELECT state,attempts,locked_by,last_result_code
      FROM notification_delivery_jobs LIMIT 1');
    acceptanceCheck($sent['state'] === 'sent' && (int) $sent['attempts'] === 2
        && $sent['locked_by'] === null && $sent['last_result_code'] === 'transport_accepted',
        'подтверждение тестового канала не сохранено');
    acceptanceCheck((int) $reopened->fetchValue(
        'SELECT COUNT(*) FROM notification_delivery_attempts') === 2,
        'после перезапуска потеряна история попыток');

    // В следующий местный день ожидается новое событие.
    $nextDay = new DateTimeImmutable('2026-10-23 07:20:00+00:00');
    $nextPublisher = new TaskOverdueReminderPublisher(
        $reopened, new NotificationService(new DatabaseNotificationStore($reopened)));
    acceptanceCheck($nextPublisher->run($nextDay, 1)['published'] === 1,
        'не создано напоминание следующего дня');

    // Обрываем обработчик после захвата и имитируем истечение аренды.
    $newQueue = new DatabaseNotificationDeliveryQueue($reopened);
    $interrupted = $newQueue->claimBatch('tasks-e2e-interrupted', 10, 300);
    acceptanceCheck(count($interrupted) === 1 && (int) $interrupted[0]['attempt_no'] === 1,
        'не подготовлено прерванное задание');
    $interruptedId = (int) $interrupted[0]['id'];
    $reopened->execute(
        "UPDATE notification_delivery_jobs SET locked_at='2000-01-01 00:00:00'
         WHERE id=:id", [':id' => $interruptedId]);
    unset($workerAfterRestart, $newQueue, $nextPublisher);

    // Третье подключение восстанавливает зависшее задание и историю отказа.
    $recovered = DatabaseManager::forConnection(new PDO($dsn, $user, $password, $options));
    $resumed = new NotificationDeliveryWorker(
        new DatabaseNotificationDeliveryQueue($recovered), [new AcceptanceChannel()]);
    $stats = $resumed->run('tasks-e2e-recovery', 10);
    acceptanceCheck($stats['claimed'] === 1 && $stats['sent'] === 1,
        'истёкшая блокировка не восстановлена');
    $attempts = $recovered->fetchAll(
        'SELECT attempt_no,result_code FROM notification_delivery_attempts
         WHERE job_id=:id ORDER BY attempt_no', [':id' => $interruptedId]);
    acceptanceCheck(count($attempts) === 2
        && $attempts[0]['result_code'] === 'worker_interrupted'
        && $attempts[1]['result_code'] === 'transport_accepted',
        'история прерывания потеряна');
    acceptanceCheck((int) $recovered->fetchValue(
        'SELECT COUNT(*) FROM notification_events') === 2,
        'восстановление создало повторные события');
    $diagnosis = (new TaskReminderCycle($recovered))->diagnose();
    acceptanceCheck((int) ($diagnosis['last']['personal_published'] ?? 0) === 1,
        'нет записи о публикации в журнале фонового цикла');
    echo "Сквозная приёмка Tasks: публикация, очередь, временный отказ, перезапуск и восстановление проверены\n";
} finally {
    // Тестовая база создана специально для проверки; не используем рабочую БД.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        acceptanceCheck(preg_match('/^[a-zA-Z0-9_]+$/', (string) $table) === 1,
            'недопустимое имя временной таблицы');
        $pdo->exec('DROP TABLE ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
