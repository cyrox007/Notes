<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('TEST_DB_PORT') ?: 3306);
$name = getenv('TEST_DB_NAME') ?: 'workspace_test';
$user = getenv('TEST_DB_USER') ?: 'workspace';
$pass = getenv('TEST_DB_PASS') ?: 'workspace';

if (!str_contains(strtolower($name), 'test')) {
    throw new RuntimeException('Испытание токенов разрешено только на тестовой базе');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysql = new mysqli($host, $user, $pass, $name, $port);
$mysql->set_charset('utf8mb4');

function tokenMysqlSchema(mysqli $mysql, string $file): void
{
    $sql = file_get_contents($file);
    if (!is_string($sql)) {
        throw new RuntimeException('Не прочитана схема');
    }
    $sql = preg_replace('/^[ \t]*--[^\r\n]*(?:\r?\n|$)/m', '', $sql) ?? '';
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') {
            $mysql->query($statement);
        }
    }
}

function tokenMysqlAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Одноразовые подтверждения MySQL: ' . $message);
    }
}

tokenMysqlSchema($mysql, $root . '/database/core_identity_schema.sql');
tokenMysqlSchema($mysql, $root . '/database/notifications_schema.sql');
tokenMysqlSchema($mysql, $root . '/database/migrations/20261021_notification_action_tokens.sql');

$suffix = bin2hex(random_bytes(5));
$uid = sprintf('eeeeeeee-eeee-4eee-8eee-%012d', random_int(1, 999999999999));
$username = 'action-token-' . $suffix;
$email = $username . '@example.test';
$insert = $mysql->prepare(
    "INSERT INTO users(uid, username, email, password_hash, firstname, lastname, role, is_active, account_status)
     VALUES (?, ?, ?, 'test', 'Действие', 'Тест', 888, 1, 'active')"
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
require_once $root . '/app/services/notifications/NotificationActionTokenStore.php';

use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\DatabaseNotificationStore;
use App\Services\Notifications\NotificationActionTokenStore;

\Core\DatabaseManager::resetInstance();
$db = \Core\DatabaseManager::getInstance();
$eventId = null;
try {
    $publisher = new NotificationService(new DatabaseNotificationStore($db));
    $published = $publisher->publish(new NotificationEvent(
        name: 'tasks.action_token_test',
        version: 1,
        idempotencyKey: 'action-token:mysql:' . $suffix,
        source: 'tasks',
        category: 'tasks.deadlines',
        importance: 'normal',
        title: 'Проверка токена',
        body: 'Без внешней отправки',
        recipientUserIds: [$userId],
        targetPath: '/tasks'
    ));
    $eventId = (int) $published['event_id'];
    $notice = $db->fetchOne(
        'SELECT id FROM notifications WHERE event_id = :event AND recipient_user_id = :user',
        [':event' => $eventId, ':user' => $userId]
    );
    tokenMysqlAssert($notice !== null, 'не создано тестовое уведомление');
    $notificationId = (int) $notice['id'];

    $tokens = new NotificationActionTokenStore($db);
    $first = $tokens->issue($userId, $notificationId, 'complete_task');
    $second = $tokens->issue($userId, $notificationId, 'complete_task');
    tokenMysqlAssert($first !== $second && strlen($first) === 64,
        'повторная отправка должна получать отдельный токен');
    tokenMysqlAssert($tokens->inspect($userId, $first, 'complete_task') === $notificationId
        && $tokens->inspect($userId, $second, 'complete_task') === $notificationId,
        'параллельные каналы не должны отзывать разрешения друг друга');
    tokenMysqlAssert($tokens->inspect($userId + 1, $first, 'complete_task') === null,
        'чужой пользователь не должен проверять токен');

    tokenMysqlAssert($tokens->consume($userId, $notificationId, $first, 'complete_task'),
        'первое погашение должно сработать');
    tokenMysqlAssert(!$tokens->consume($userId, $notificationId, $first, 'complete_task'),
        'повторное погашение должно быть запрещено');
    tokenMysqlAssert($tokens->inspect($userId, $second, 'complete_task') === $notificationId,
        'погашение первого токена не должно затрагивать второй');

    $db->execute(
        'UPDATE notification_action_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND
         WHERE token_hash = :hash',
        [':hash' => hash('sha256', $second)]
    );
    tokenMysqlAssert($tokens->inspect($userId, $second, 'complete_task') === null,
        'просроченный токен не должен проходить проверку');
    tokenMysqlAssert(!$tokens->consume($userId, $notificationId, $second, 'complete_task'),
        'просроченный токен нельзя погасить');
    tokenMysqlAssert($tokens->purgeExpired(1) === 1,
        'очистка должна удалить ровно один просроченный токен');
    tokenMysqlAssert($tokens->purgeExpired(100) === 0,
        'сохранённый использованный, но ещё не просроченный токен нельзя удалить');

    foreach ([0, 1001] as $limit) {
        $rejected = false;
        try {
            $tokens->purgeExpired($limit);
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        tokenMysqlAssert($rejected, 'запрещён недопустимый размер пакета очистки');
    }
    echo "Одноразовые действия: повторы, чужие аккаунты, истечение и пакетная очистка проверены\n";
} finally {
    if ($eventId !== null) {
        $db->execute('DELETE FROM notification_events WHERE id = :id', [':id' => $eventId]);
    }
    $db->execute('DELETE FROM users WHERE id = :id', [':id' => $userId]);
    \Core\DatabaseManager::resetInstance();
}
