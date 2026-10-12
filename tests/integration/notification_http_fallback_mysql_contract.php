<?php

declare(strict_types=1);

use App\Services\Notifications\NotificationDeliveryRuntime;
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

function notificationHttpMysqlAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function notificationHttpMysqlApplySql(mysqli $db, string $path): void
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

notificationHttpMysqlApplySql($mysqli, $root . '/database/core_identity_schema.sql');
notificationHttpMysqlApplySql($mysqli, $root . '/database/migrations/20261010_notifications_foundation.sql');
notificationHttpMysqlApplySql($mysqli, $root . '/database/migrations/20261012_notifications_http_fallback.sql');

$mysqli->query('SET FOREIGN_KEY_CHECKS=0');
foreach ([
    'notification_delivery_attempts',
    'notification_delivery_jobs',
    'notifications',
    'notification_events',
    'notification_channel_configs',
    'notification_runtime_state',
] as $table) {
    $mysqli->query('TRUNCATE TABLE `' . $table . '`');
}
$mysqli->query('SET FOREIGN_KEY_CHECKS=1');

$username = 'notification-http-fallback-user';
$email = 'notification-http-fallback@example.test';
$uid = '50000000-0000-4000-8000-000000000001';
$stmt = $mysqli->prepare(
    "INSERT INTO users (uid, username, email, password_hash, firstname, lastname, role, is_active, account_status)\n"
    . "VALUES (?, ?, ?, 'hash', 'HTTP', 'Fallback', 888, 1, 'active')\n"
    . "ON DUPLICATE KEY UPDATE email = VALUES(email), is_active = 1, account_status = 'active'"
);
$stmt->bind_param('sss', $uid, $username, $email);
$stmt->execute();
$stmt->close();

$stmt = $mysqli->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$stmt->bind_param('s', $username);
$stmt->execute();
$userId = (int) $stmt->get_result()->fetch_assoc()['id'];
$stmt->close();
notificationHttpMysqlAssert($userId > 0, 'Не удалось подготовить пользователя HTTP fallback');

$mysqli->query(
    "INSERT INTO notification_channel_configs (channel, owner_user_id, owner_scope, label, enabled, config_json, secret_ref)\n"
    . "VALUES ('webhook', NULL, 0, 'HTTP fallback test', 1, JSON_OBJECT(), NULL)"
);
$configId = (int) $mysqli->insert_id;

for ($index = 1; $index <= 5; $index++) {
    $key = 'http-fallback-' . $index;
    $title = 'HTTP fallback #' . $index;
    $stmt = $mysqli->prepare(
        "INSERT INTO notification_events (event_name, event_version, idempotency_key, source_name, category, importance, occurred_at, payload_json, created_at)\n"
        . "VALUES ('system.http_fallback_test', 1, ?, 'system', 'system.health', 'normal', UTC_TIMESTAMP(), JSON_OBJECT(), UTC_TIMESTAMP())"
    );
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $eventId = (int) $mysqli->insert_id;
    $stmt->close();

    $stmt = $mysqli->prepare(
        "INSERT INTO notifications (event_id, recipient_user_id, title, body, target_path, created_at)\n"
        . "VALUES (?, ?, ?, 'Проверка ограниченного резервного прохода', '/system', UTC_TIMESTAMP())"
    );
    $stmt->bind_param('iis', $eventId, $userId, $title);
    $stmt->execute();
    $notificationId = (int) $mysqli->insert_id;
    $stmt->close();

    $recipientRef = 'user:' . $userId;
    $stmt = $mysqli->prepare(
        "INSERT INTO notification_delivery_jobs\n"
        . "(notification_id, channel_config_id, channel, recipient_ref, state, attempts, max_attempts, next_attempt_at, created_at, updated_at)\n"
        . "VALUES (?, ?, 'webhook', ?, 'pending', 0, 5, '2000-01-01 00:00:00', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $stmt->bind_param('iis', $notificationId, $configId, $recipientRef);
    $stmt->execute();
    $stmt->close();
}

putenv('DBDRIVER=mysql');
putenv('DBHOST=' . $dbHost);
putenv('DBPORT=' . $dbPort);
putenv('DBNAME=' . $dbName);
putenv('DBUSER=' . $dbUser);
putenv('DBPASS=' . $dbPass);
putenv('SITEURL=https://workspace.example.test');

require_once $root . '/core/Config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/app/services/notifications/NotificationDeliveryRuntime.php';

DatabaseManager::resetInstance();
$runtime = new NotificationDeliveryRuntime(DatabaseManager::getInstance());
$utc = new DateTimeZone('UTC');
$start = new DateTimeImmutable('2030-01-01 12:00:00', $utc);

$first = $runtime->runHttpFallback($start);
notificationHttpMysqlAssert($first['claimed'] === 3, 'Первый HTTP-проход должен захватывать не более трёх заданий');
notificationHttpMysqlAssert($first['retry'] === 3, 'Неизвестный внешний канал должен безопасно перейти в retry');
notificationHttpMysqlAssert(!$first['locked'] && !$first['throttled'], 'Первый HTTP-проход не должен быть заблокирован');

$second = $runtime->runHttpFallback($start->modify('+30 seconds'));
notificationHttpMysqlAssert($second['throttled'], 'Повторный HTTP-проход раньше минуты должен быть подавлен');
notificationHttpMysqlAssert($second['claimed'] === 0, 'Throttle не должен захватывать задания');

$lockRow = $mysqli->query("SELECT GET_LOCK('workspace.notifications.delivery.http_fallback', 0) AS acquired")->fetch_assoc();
notificationHttpMysqlAssert((int) ($lockRow['acquired'] ?? 0) === 1, 'Не удалось занять тестовую advisory-блокировку');
$locked = $runtime->runHttpFallback($start->modify('+61 seconds'));
notificationHttpMysqlAssert($locked['locked'], 'Параллельный HTTP-проход должен завершаться без ожидания');
notificationHttpMysqlAssert($locked['claimed'] === 0, 'Занятая блокировка не должна захватывать задания');
$mysqli->query("SELECT RELEASE_LOCK('workspace.notifications.delivery.http_fallback')");

$last = $runtime->runHttpFallback($start->modify('+62 seconds'));
notificationHttpMysqlAssert($last['claimed'] === 2, 'Следующий разрешённый HTTP-проход должен забрать оставшиеся задания');
notificationHttpMysqlAssert($last['retry'] === 2, 'Оставшиеся задания должны перейти в контролируемый retry');

$jobState = $mysqli->query(
    "SELECT COUNT(*) AS total, SUM(state = 'retry') AS retried, SUM(attempts = 1) AS attempted_once FROM notification_delivery_jobs"
)->fetch_assoc();
notificationHttpMysqlAssert((int) $jobState['total'] === 5, 'Тест должен сохранить пять заданий');
notificationHttpMysqlAssert((int) $jobState['retried'] === 5, 'Все пять заданий должны быть обработаны ровно двумя проходами');
notificationHttpMysqlAssert((int) $jobState['attempted_once'] === 5, 'Fallback не должен дублировать попытки');

$attempts = (int) $mysqli->query('SELECT COUNT(*) AS total FROM notification_delivery_attempts')->fetch_assoc()['total'];
notificationHttpMysqlAssert($attempts === 5, 'Для пяти заданий должно быть ровно пять записей попыток');

$state = $mysqli->query(
    "SELECT last_http_run_at FROM notification_runtime_state WHERE state_key = 'delivery_http_fallback' LIMIT 1"
)->fetch_assoc();
notificationHttpMysqlAssert(
    ($state['last_http_run_at'] ?? null) === '2030-01-01 12:01:02',
    'Runtime должен сохранять время последнего разрешённого HTTP-прохода'
);

DatabaseManager::resetInstance();
$mysqli->close();
fwrite(STDOUT, "[OK] MySQL HTTP fallback внешних уведомлений 1.1.0\n");
