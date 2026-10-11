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

function notificationDeliveryUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function notificationDeliveryUiApplySql(mysqli $db, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) {
        throw new RuntimeException('Не удалось прочитать SQL: ' . $path);
    }
    $statements = preg_split('/;\s*(?:\R|$)/', $sql) ?: [];
    foreach ($statements as $index => $statement) {
        $statement = trim($statement);
        if ($statement === '') continue;
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

function notificationDeliveryUiUser(mysqli $db, string $uid, string $username): int
{
    $email = $username . '@example.test';
    $stmt = $db->prepare(
        "INSERT INTO users (uid, username, email, password_hash, firstname, lastname, role, is_active, account_status)\n"
        . "VALUES (?, ?, ?, 'hash', 'Тест', 'Доставка', 888, 1, 'active')"
    );
    $stmt->bind_param('sss', $uid, $username, $email);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

function notificationDeliveryUiNotification(mysqli $db, int $userId, string $key, string $title, string $createdAt): int
{
    $stmt = $db->prepare(
        "INSERT INTO notification_events (event_name, event_version, idempotency_key, source_name, category, importance, occurred_at, payload_json, created_at)\n"
        . "VALUES ('system.delivery_test', 1, ?, 'system', 'system.health', 'normal', ?, JSON_OBJECT(), ?)"
    );
    $stmt->bind_param('sss', $key, $createdAt, $createdAt);
    $stmt->execute();
    $eventId = (int) $db->insert_id;
    $stmt->close();

    $stmt = $db->prepare(
        "INSERT INTO notifications (event_id, recipient_user_id, title, body, target_path, created_at)\n"
        . "VALUES (?, ?, ?, 'Проверка безопасного состояния доставки', '/system', ?)"
    );
    $stmt->bind_param('iiss', $eventId, $userId, $title, $createdAt);
    $stmt->execute();
    $notificationId = (int) $db->insert_id;
    $stmt->close();
    return $notificationId;
}

function notificationDeliveryUiJob(mysqli $db, int $notificationId, int $configId, string $state, string $resultCode): void
{
    $recipientRef = 'user:test';
    $stmt = $db->prepare(
        "INSERT INTO notification_delivery_jobs (notification_id, channel_config_id, channel, recipient_ref, state, attempts, max_attempts, next_attempt_at, last_result_code, sent_at, created_at, updated_at)\n"
        . "VALUES (?, ?, 'email', ?, ?, 1, 5, '2026-10-11 10:00:00', ?, IF(? = 'sent', '2026-10-11 10:01:00', NULL), '2026-10-11 10:00:00', '2026-10-11 10:00:00')"
    );
    $stmt->bind_param('iissss', $notificationId, $configId, $recipientRef, $state, $resultCode, $state);
    $stmt->execute();
    $stmt->close();
}

notificationDeliveryUiApplySql($mysqli, $root . '/database/core_identity_schema.sql');
notificationDeliveryUiApplySql($mysqli, $root . '/database/migrations/20261010_notifications_foundation.sql');

$userId = notificationDeliveryUiUser(
    $mysqli,
    '30000000-0000-4000-8000-000000000001',
    'delivery-ui-user'
);

$mysqli->query(
    "INSERT INTO notification_channel_configs (channel, owner_user_id, owner_scope, label, enabled, config_json, secret_ref) VALUES\n"
    . "('email', NULL, 0, 'UI доставка A', 1, JSON_OBJECT('host', 'secret-host-a.test'), 'env:UI_DELIVERY_SECRET_A'),\n"
    . "('email', NULL, 0, 'UI доставка B', 1, JSON_OBJECT('host', 'secret-host-b.test'), 'env:UI_DELIVERY_SECRET_B')"
);
$configA = (int) $mysqli->insert_id;
$configB = $configA + 1;

$none = notificationDeliveryUiNotification($mysqli, $userId, 'ui-delivery-none', 'Нет внешней доставки', '2026-10-11 10:00:00');
$pending = notificationDeliveryUiNotification($mysqli, $userId, 'ui-delivery-pending', 'Ожидает доставку', '2026-10-11 10:01:00');
$sent = notificationDeliveryUiNotification($mysqli, $userId, 'ui-delivery-sent', 'Доставлено', '2026-10-11 10:02:00');
$failed = notificationDeliveryUiNotification($mysqli, $userId, 'ui-delivery-failed', 'Ошибка доставки', '2026-10-11 10:03:00');
$partial = notificationDeliveryUiNotification($mysqli, $userId, 'ui-delivery-partial', 'Частичная доставка', '2026-10-11 10:04:00');

notificationDeliveryUiJob($mysqli, $pending, $configA, 'retry', 'smtp_temporary');
notificationDeliveryUiJob($mysqli, $sent, $configA, 'sent', 'sent');
notificationDeliveryUiJob($mysqli, $failed, $configA, 'failed', 'smtp_permanent');
notificationDeliveryUiJob($mysqli, $partial, $configA, 'sent', 'sent');
notificationDeliveryUiJob($mysqli, $partial, $configB, 'failed', 'smtp_permanent');

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
$center = new DatabaseNotificationCenter(DatabaseManager::getInstance());
$result = $center->listForUser($userId, [], 1, 20);
$byTitle = [];
foreach ($result['items'] as $item) {
    $byTitle[(string) $item['title']] = $item;
}

notificationDeliveryUiAssert(($byTitle['Нет внешней доставки']['delivery']['state'] ?? null) === 'none', 'Уведомление без jobs должно иметь delivery=none');
notificationDeliveryUiAssert(($byTitle['Ожидает доставку']['delivery']['state'] ?? null) === 'pending', 'retry должен отображаться как ожидающая доставка');
notificationDeliveryUiAssert(($byTitle['Доставлено']['delivery']['state'] ?? null) === 'sent', 'sent должен отображаться как успешная доставка');
notificationDeliveryUiAssert(($byTitle['Ошибка доставки']['delivery']['state'] ?? null) === 'failed', 'failed должен отображаться как ошибка доставки');
notificationDeliveryUiAssert(($byTitle['Частичная доставка']['delivery']['state'] ?? null) === 'partial', 'sent+failed должны отображаться как частичная доставка');
notificationDeliveryUiAssert(($byTitle['Частичная доставка']['delivery']['sent'] ?? null) === 1, 'Безопасный агрегат должен считать успешные каналы');
notificationDeliveryUiAssert(($byTitle['Частичная доставка']['delivery']['failed'] ?? null) === 1, 'Безопасный агрегат должен считать ошибки каналов');

$encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
foreach (['secret-host-a.test', 'secret-host-b.test', 'UI_DELIVERY_SECRET_A', 'UI_DELIVERY_SECRET_B', 'smtp_permanent', 'smtp_temporary'] as $forbidden) {
    notificationDeliveryUiAssert(!str_contains($encoded, $forbidden), 'Центр не должен раскрывать внутреннюю диагностику доставки: ' . $forbidden);
}

DatabaseManager::resetInstance();
$mysqli->close();
fwrite(STDOUT, "[OK] MySQL-контракт состояния доставки в центре уведомлений 1.1.0\n");
