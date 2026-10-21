<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('TEST_DB_PORT') ?: 3306);
$name = getenv('TEST_DB_NAME') ?: 'workspace_test';
$user = getenv('TEST_DB_USER') ?: 'workspace';
$pass = getenv('TEST_DB_PASS') ?: 'workspace';

if (!str_contains(strtolower($name), 'test')) {
    throw new RuntimeException('Проверка доставки разрешена только на тестовой базе');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysql = new mysqli($host, $user, $pass, $name, $port);
$mysql->set_charset('utf8mb4');

function deliveryActionAssert(bool $valid, string $message): void
{
    if (!$valid) {
        throw new RuntimeException('Сквозная доставка действий: ' . $message);
    }
}

function deliveryActionSchema(mysqli $mysql, string $file): void
{
    $source = file_get_contents($file);
    if (!is_string($source)) {
        throw new RuntimeException('Не прочитана схема');
    }
    $source = preg_replace('/^[ \t]*--[^\r\n]*(?:\r?\n|$)/m', '', $source) ?? '';
    foreach (explode(';', $source) as $sql) {
        if (trim($sql) !== '') {
            $mysql->query($sql);
        }
    }
}

deliveryActionSchema($mysql, $root . '/database/core_identity_schema.sql');
deliveryActionSchema($mysql, $root . '/database/notifications_schema.sql');
deliveryActionSchema($mysql, $root . '/database/migrations/20261020_notifications_max_channel.sql');
deliveryActionSchema($mysql, $root . '/database/migrations/20261021_notification_action_tokens.sql');

$suffix = bin2hex(random_bytes(5));
$uid = sprintf('eeeeeeee-eeee-4eee-8eee-%012d', random_int(1, 999999999999));
$username = 'delivery-action-' . $suffix;
$email = $username . '@example.test';
$insert = $mysql->prepare(
    "INSERT INTO users(uid, username, email, password_hash, firstname, lastname, role, is_active, account_status)
     VALUES (?, ?, ?, 'test', 'Доставка', 'Испытание', 888, 1, 'active')"
);
$insert->bind_param('sss', $uid, $username, $email);
$insert->execute();
$userId = (int) $mysql->insert_id;
$insert->close();

putenv('DBDRIVER=mysql');
putenv('DBHOST=' . $host);
putenv('DBPORT=' . $port);
putenv('DBNAME=' . $name);
putenv('DBUSER=' . $user);
putenv('DBPASS=' . $pass);

require_once $root . '/core/Config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/app/services/notifications/NotificationService.php';
require_once $root . '/app/services/notifications/DatabaseNotificationStore.php';
require_once $root . '/app/services/notifications/NotificationDeliveryActionLinks.php';
require_once $root . '/app/services/notifications/NotificationDeliveryWorker.php';

use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\DatabaseNotificationStore;
use App\Services\Notifications\NotificationActionTokenStore;
use App\Services\Notifications\NotificationDeliveryActionLinks;
use App\Services\Notifications\DatabaseNotificationDeliveryQueue;
use App\Services\Notifications\NotificationDeliveryWorker;
use App\Services\Notifications\NotificationDeliveryChannel;
use App\Services\Notifications\NotificationDeliveryResult;

final class DeliveryActionCaptureChannel implements NotificationDeliveryChannel
{
    /** @var list<array<string,mixed>> */
    public array $received = [];
    public bool $failOnce = false;

    public function __construct(private string $channel)
    {
    }

    public function name(): string
    {
        return $this->channel;
    }

    public function deliver(array $job): NotificationDeliveryResult
    {
        $this->received[] = $job;
        if ($this->failOnce) {
            $this->failOnce = false;
            return NotificationDeliveryResult::failed('test_retry', 'Искусственный временный отказ', true);
        }
        return NotificationDeliveryResult::sent('test_accepted');
    }
}

\Core\DatabaseManager::resetInstance();
$db = \Core\DatabaseManager::getInstance();
$eventId = null;
try {
    foreach (['telegram', 'max'] as $channel) {
        $db->execute(
            'INSERT INTO notification_channel_configs
             (channel, owner_user_id, owner_scope, label, enabled, config_json)
             VALUES (:channel, :user, :scope, :label, 1, :config)',
            [
                ':channel' => $channel, ':user' => $userId, ':scope' => $userId,
                ':label' => 'smoke-' . $suffix,
                ':config' => json_encode(['recipient' => 'test'], JSON_THROW_ON_ERROR),
            ]
        );
    }
    $db->execute(
        'INSERT INTO notification_subscriptions
         (user_id, category, enabled, telegram_enabled, max_enabled)
         VALUES (:user, :category, 1, 1, 1)',
        [':user' => $userId, ':category' => 'tasks.action_smoke']
    );

    $publisher = new NotificationService(new DatabaseNotificationStore($db));
    $result = $publisher->publish(new NotificationEvent(
        name: 'tasks.action_delivery_smoke',
        version: 1,
        idempotencyKey: 'action-delivery:' . $suffix,
        source: 'tasks',
        category: 'tasks.action_smoke',
        importance: 'normal',
        title: 'Заголовок закрыт',
        body: 'Текст закрыт',
        recipientUserIds: [$userId],
        payload: ['task_uid' => str_repeat('a', 32)],
        targetPath: '/tasks'
    ));
    $eventId = (int) $result['event_id'];
    $rows = $db->fetchAll(
        'SELECT j.id, j.channel, j.notification_id
         FROM notification_delivery_jobs j
         JOIN notifications n ON n.id = j.notification_id
         WHERE n.event_id = :event ORDER BY j.id',
        [':event' => $eventId]
    );
    deliveryActionAssert(count($rows) === 2, 'для двух персональных каналов нужны два задания');

    $telegram = new DeliveryActionCaptureChannel('telegram');
    $max = new DeliveryActionCaptureChannel('max');
    $telegram->failOnce = true;
    $tokens = new NotificationActionTokenStore($db);
    $worker = new NotificationDeliveryWorker(
        new DatabaseNotificationDeliveryQueue($db),
        [$telegram, $max],
        new NotificationDeliveryActionLinks($tokens, 'https://notes.example.test')
    );

    $ids = [];
    foreach ($rows as $row) {
        $ids[$row['channel']] = (int) $row['id'];
        deliveryActionAssert((int) $row['notification_id'] > 0, 'пустой идентификатор уведомления');
    }

    $first = $worker->runJob('smoke-telegram', $ids['telegram']);
    deliveryActionAssert($first['retry'] === 1, 'первый временный отказ должен дать повтор');
    $sent = $worker->runJob('smoke-max', $ids['max']);
    deliveryActionAssert($sent['sent'] === 1, 'независимый канал должен отправиться');

    // Отдельно проверяем, что очередь захватывает и возвращает notification_id,
    // а адаптер получает именно URL подтверждения для того же уведомления.
    $db->execute(
        "UPDATE notification_delivery_jobs SET next_attempt_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND
         WHERE id = :id",
        [':id' => $ids['telegram']]
    );
    $retry = $worker->runJob('smoke-telegram-retry', $ids['telegram']);
    deliveryActionAssert($retry['sent'] === 1, 'повторная отправка должна завершиться');

    $firstJob = $telegram->received[0] ?? [];
    $retryJob = $telegram->received[1] ?? [];
    $maxJob = $max->received[0] ?? [];
    $expectedNotice = (int) $rows[0]['notification_id'];
    $urls = [];
    foreach ([$firstJob, $retryJob, $maxJob] as $job) {
        deliveryActionAssert((int) ($job['notification_id'] ?? 0) === $expectedNotice,
            'канал получил неверный идентификатор уведомления');
        $url = (string) ($job['action_url'] ?? '');
        deliveryActionAssert(str_starts_with(
            $url, 'https://notes.example.test/tasks/notification/' . $expectedNotice . '/preview#token='
        ), 'адаптер не получил рабочую ссылку');
        $secret = substr($url, strrpos($url, '=') + 1);
        deliveryActionAssert($tokens->inspect($userId, $secret, 'complete_task') === $expectedNotice,
            'выданная ссылка не открывается для получателя');
        $urls[] = $secret;
    }
    deliveryActionAssert(count(array_unique($urls)) === 3, 'повтор и два канала должны иметь независимые ссылки');
    deliveryActionAssert($tokens->inspect($userId + 1, $urls[0], 'complete_task') === null,
        'ссылка недоступна другому пользователю');
    deliveryActionAssert($tokens->consume($userId, $expectedNotice, $urls[0], 'complete_task'),
        'разрешение не удалось погасить');
    deliveryActionAssert(!$tokens->consume($userId, $expectedNotice, $urls[0], 'complete_task'),
        'разрешение допускает повторное погашение');

    $attempts = $db->fetchAll(
        'SELECT a.result_code, a.error_summary
         FROM notification_delivery_attempts a
         WHERE a.job_id IN (:a, :b)',
        [':a' => $ids['telegram'], ':b' => $ids['max']]
    );
    deliveryActionAssert(count($attempts) === 3, 'нужна история всех трёх попыток');
    foreach ($attempts as $attempt) {
        $logged = json_encode($attempt, JSON_THROW_ON_ERROR);
        foreach ($urls as $secret) {
            deliveryActionAssert(!str_contains($logged, $secret), 'секрет попал в журнал доставки');
        }
    }
    echo "Сквозная MySQL-проверка: очередь, повтор, два канала, ссылка и токен успешно\n";
} finally {
    if ($eventId !== null) {
        $db->execute('DELETE FROM notification_events WHERE id = :id', [':id' => $eventId]);
    }
    $db->execute('DELETE FROM users WHERE id = :id', [':id' => $userId]);
    \Core\DatabaseManager::resetInstance();
}
