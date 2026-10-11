<?php

declare(strict_types=1);

use App\Services\Notifications\DatabaseNotificationCenter;
use Core\DatabaseManager;

$root = dirname(__DIR__, 2);
$dbHost = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$dbPort = (int) (getenv('TEST_DB_PORT') ?: 3306);
$dbName = getenv('TEST_DB_NAME') ?: 'workspace_test';
$dbUser = getenv('TEST_DB_USER') ?: 'workspace';
$dbPass = getenv('TEST_DB_PASS') ?: 'workspace';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$mysqli->set_charset('utf8mb4');

function notificationCenterMysqlAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function notificationCenterApplySql(mysqli $db, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) {
        throw new RuntimeException('Не удалось прочитать SQL: ' . $path);
    }

    $statements = preg_split('/;\s*(?:\R|$)/', $sql) ?: [];
    foreach ($statements as $index => $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        try {
            $db->query($statement);
        } catch (mysqli_sql_exception $e) {
            throw new RuntimeException(
                'Ошибка SQL #' . ($index + 1) . ' в ' . basename($path) . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}

function notificationCenterInsertUser(mysqli $db, string $uid, string $username, string $email): int
{
    $stmt = $db->prepare(
        "INSERT INTO users (uid, username, email, password_hash, firstname, lastname, role, is_active, account_status)\n"
        . "VALUES (?, ?, ?, 'hash', 'Тест', 'Пользователь', 888, 1, 'active')"
    );
    $stmt->bind_param('sss', $uid, $username, $email);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

function notificationCenterInsertEvent(
    mysqli $db,
    string $name,
    string $key,
    string $source,
    string $category,
    string $importance,
    string $createdAt
): int {
    $stmt = $db->prepare(
        "INSERT INTO notification_events (\n"
        . "event_name, event_version, idempotency_key, source_name, category, importance, occurred_at, payload_json, created_at\n"
        . ") VALUES (?, 1, ?, ?, ?, ?, ?, JSON_OBJECT(), ?)"
    );
    $stmt->bind_param('sssssss', $name, $key, $source, $category, $importance, $createdAt, $createdAt);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

function notificationCenterInsertNotification(
    mysqli $db,
    int $eventId,
    int $userId,
    string $title,
    string $createdAt,
    ?string $readAt = null
): int {
    $stmt = $db->prepare(
        'INSERT INTO notifications (event_id, recipient_user_id, title, body, target_path, read_at, created_at) '
        . "VALUES (?, ?, ?, 'Текст проверки', '/test', ?, ?)"
    );
    $stmt->bind_param('iisss', $eventId, $userId, $title, $readAt, $createdAt);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

notificationCenterApplySql($mysqli, $root . '/database/core_identity_schema.sql');
notificationCenterApplySql($mysqli, $root . '/database/migrations/20261010_notifications_foundation.sql');

$userA = notificationCenterInsertUser(
    $mysqli,
    '10000000-0000-4000-8000-000000000001',
    'center-user-a',
    'center-a@example.test'
);
$userB = notificationCenterInsertUser(
    $mysqli,
    '10000000-0000-4000-8000-000000000002',
    'center-user-b',
    'center-b@example.test'
);

$eventA1 = notificationCenterInsertEvent($mysqli, 'calendar.reminder_due', 'center-a1', 'calendar', 'calendar.reminders', 'high', '2026-10-11 08:00:00');
$eventA2 = notificationCenterInsertEvent($mysqli, 'task.due_soon', 'center-a2', 'tasks', 'tasks.deadlines', 'normal', '2026-10-11 09:00:00');
$eventA3 = notificationCenterInsertEvent($mysqli, 'system.storage_low', 'center-a3', 'system', 'system.storage', 'critical', '2026-10-11 10:00:00');
$eventB1 = notificationCenterInsertEvent($mysqli, 'calendar.reminder_due', 'center-b1', 'calendar', 'calendar.reminders', 'high', '2026-10-11 11:00:00');

$notificationA1 = notificationCenterInsertNotification($mysqli, $eventA1, $userA, 'A1', '2026-10-11 08:00:00');
$notificationA2 = notificationCenterInsertNotification($mysqli, $eventA2, $userA, 'A2', '2026-10-11 09:00:00', '2026-10-11 09:30:00');
$notificationA3 = notificationCenterInsertNotification($mysqli, $eventA3, $userA, 'A3', '2026-10-11 10:00:00');
$notificationB1 = notificationCenterInsertNotification($mysqli, $eventB1, $userB, 'B1', '2026-10-11 11:00:00');

putenv('DBDRIVER=mysql');
putenv('DBHOST=' . $dbHost);
putenv('DBPORT=' . $dbPort);
putenv('DBNAME=' . $dbName);
putenv('DBUSER=' . $dbUser);
putenv('DBPASS=' . $dbPass);

require_once $root . '/core/Config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/app/services/notifications/DatabaseNotificationCenter.php';

DatabaseManager::resetInstance();
$db = DatabaseManager::getInstance();
$center = new DatabaseNotificationCenter($db);

notificationCenterMysqlAssert($center->unreadCount($userA) === 2, 'У пользователя A должно быть два непрочитанных уведомления');
notificationCenterMysqlAssert($center->unreadCount($userB) === 1, 'У пользователя B должно быть одно непрочитанное уведомление');

$list = $center->listForUser($userA, [], 1, 2);
notificationCenterMysqlAssert($list['pagination'] === [
    'page' => 1,
    'limit' => 2,
    'total' => 3,
    'total_pages' => 2,
], 'Пагинация пользователя A должна считать только его уведомления');
notificationCenterMysqlAssert(
    array_column($list['items'], 'id') === [$notificationA3, $notificationA2],
    'Список должен быть стабильно отсортирован от новых к старым'
);
notificationCenterMysqlAssert($list['unread_count'] === 2, 'Список должен возвращать актуальный счётчик непрочитанных');
notificationCenterMysqlAssert(!array_key_exists('payload_json', $list['items'][0]), 'Полезная нагрузка события не должна попадать в центр уведомлений');

$pageTwo = $center->listForUser($userA, [], 2, 2);
notificationCenterMysqlAssert(array_column($pageTwo['items'], 'id') === [$notificationA1], 'Вторая страница должна содержать оставшееся уведомление');

$unread = $center->listForUser($userA, ['status' => 'unread'], 1, 20);
notificationCenterMysqlAssert(array_column($unread['items'], 'id') === [$notificationA3, $notificationA1], 'Фильтр unread должен исключать прочитанные уведомления');
$read = $center->listForUser($userA, ['status' => 'read'], 1, 20);
notificationCenterMysqlAssert(array_column($read['items'], 'id') === [$notificationA2], 'Фильтр read должен возвращать только прочитанные уведомления');
$calendar = $center->listForUser($userA, ['category' => 'calendar.reminders'], 1, 20);
notificationCenterMysqlAssert(array_column($calendar['items'], 'id') === [$notificationA1], 'Фильтр категории должен быть точным');
$critical = $center->listForUser($userA, ['importance' => 'critical'], 1, 20);
notificationCenterMysqlAssert(array_column($critical['items'], 'id') === [$notificationA3], 'Фильтр важности должен быть точным');
$tasks = $center->listForUser($userA, ['source' => 'tasks'], 1, 20);
notificationCenterMysqlAssert(array_column($tasks['items'], 'id') === [$notificationA2], 'Фильтр источника должен быть точным');

$center->markRead($userA, $notificationA3);
notificationCenterMysqlAssert($center->unreadCount($userA) === 1, 'Отметка read должна уменьшать счётчик');
$center->markRead($userA, $notificationA3);
notificationCenterMysqlAssert($center->unreadCount($userA) === 1, 'Повторная отметка read должна быть идемпотентной');

$center->markRead($userA, $notificationB1);
notificationCenterMysqlAssert($center->unreadCount($userB) === 1, 'Пользователь A не должен менять уведомление пользователя B');

$center->markUnread($userA, $notificationA2);
notificationCenterMysqlAssert($center->unreadCount($userA) === 2, 'Отметка unread должна увеличить счётчик');
$center->markUnread($userA, $notificationA2);
notificationCenterMysqlAssert($center->unreadCount($userA) === 2, 'Повторная отметка unread должна быть идемпотентной');

notificationCenterMysqlAssert($center->markAllRead($userA) === 2, 'Массовая отметка должна затронуть только непрочитанные пользователя A');
notificationCenterMysqlAssert($center->unreadCount($userA) === 0, 'После mark-all у пользователя A не должно остаться непрочитанных');
notificationCenterMysqlAssert($center->markAllRead($userA) === 0, 'Повторный mark-all должен быть идемпотентным');
notificationCenterMysqlAssert($center->unreadCount($userB) === 1, 'Массовая отметка пользователя A не должна затронуть пользователя B');

$invalidStatusRejected = false;
try {
    $center->listForUser($userA, ['status' => 'unknown'], 1, 20);
} catch (InvalidArgumentException) {
    $invalidStatusRejected = true;
}
notificationCenterMysqlAssert($invalidStatusRejected, 'Неизвестный status должен отклоняться до SQL-запроса');

$oversizedPageRejected = false;
try {
    $center->listForUser($userA, [], 1, 101);
} catch (InvalidArgumentException) {
    $oversizedPageRejected = true;
}
notificationCenterMysqlAssert($oversizedPageRejected, 'Размер страницы больше 100 должен отклоняться');

DatabaseManager::resetInstance();
$mysqli->close();
fwrite(STDOUT, "[OK] MySQL-контракт API центра уведомлений 1.1.0\n");
