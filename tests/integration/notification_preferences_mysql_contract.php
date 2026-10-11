<?php

declare(strict_types=1);

use App\Services\Notifications\DatabaseNotificationDeliveryQueue;
use App\Services\Notifications\DatabaseNotificationPreferences;
use App\Services\Notifications\NotificationEvent;
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

function notificationPreferencesMysqlAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function notificationPreferencesApplySql(mysqli $db, string $path): void
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

function notificationPreferencesInsertUser(mysqli $db, string $uid, string $username, string $email): int
{
    $stmt = $db->prepare(
        "INSERT INTO users (uid, username, email, password_hash, firstname, lastname, role, is_active, account_status)\n"
        . "VALUES (?, ?, ?, 'hash', 'Тест', 'Подписки', 888, 1, 'active')"
    );
    $stmt->bind_param('sss', $uid, $username, $email);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

function notificationPreferencesInsertEvent(mysqli $db, string $key, string $category): int
{
    $stmt = $db->prepare(
        "INSERT INTO notification_events (\n"
        . "event_name, event_version, idempotency_key, source_name, category, importance, occurred_at, payload_json, created_at\n"
        . ") VALUES ('calendar.reminder_due', 1, ?, 'calendar', ?, 'normal', '2026-10-11 10:00:00', JSON_OBJECT(), '2026-10-11 10:00:00')"
    );
    $stmt->bind_param('ss', $key, $category);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

function notificationPreferencesInsertNotification(mysqli $db, int $eventId, int $userId, string $title): int
{
    $stmt = $db->prepare(
        "INSERT INTO notifications (event_id, recipient_user_id, title, body, target_path, created_at)\n"
        . "VALUES (?, ?, ?, 'Проверка пользовательской подписки', '/calendar', '2026-10-11 10:00:00')"
    );
    $stmt->bind_param('iis', $eventId, $userId, $title);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

function notificationPreferencesFind(array $summary, string $category): array
{
    foreach (($summary['subscriptions'] ?? []) as $subscription) {
        if (($subscription['category'] ?? null) === $category) {
            return $subscription;
        }
    }
    throw new RuntimeException('Категория отсутствует в ответе настроек: ' . $category);
}

notificationPreferencesApplySql($mysqli, $root . '/database/core_identity_schema.sql');
notificationPreferencesApplySql($mysqli, $root . '/database/migrations/20261010_notifications_foundation.sql');

$userA = notificationPreferencesInsertUser(
    $mysqli,
    '20000000-0000-4000-8000-000000000001',
    'preferences-user-a',
    'preferences-a@example.test'
);
$userB = notificationPreferencesInsertUser(
    $mysqli,
    '20000000-0000-4000-8000-000000000002',
    'preferences-user-b',
    'broken-address'
);

$category = 'calendar.preferences-test';
$observedEvent = notificationPreferencesInsertEvent($mysqli, 'preferences-observed-a', $category);
notificationPreferencesInsertNotification($mysqli, $observedEvent, $userA, 'Наблюдаемая категория');

$mysqli->query(
    "INSERT INTO notification_channel_configs (channel, owner_user_id, owner_scope, label, enabled, config_json, secret_ref)\n"
    . "VALUES ('email', NULL, 0, 'Проверка API подписок', 1, "
    . "JSON_OBJECT('host', 'smtp.preferences.test', 'port', 587, 'encryption', 'starttls', 'sender_email', 'no-reply@example.test'), "
    . "'env:PREFERENCES_SMTP_SECRET')"
);

putenv('DBDRIVER=mysql');
putenv('DBHOST=' . $dbHost);
putenv('DBPORT=' . $dbPort);
putenv('DBNAME=' . $dbName);
putenv('DBUSER=' . $dbUser);
putenv('DBPASS=' . $dbPass);

require_once $root . '/core/Config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/app/services/notifications/NotificationEvent.php';
require_once $root . '/app/services/notifications/DatabaseNotificationDeliveryQueue.php';
require_once $root . '/app/services/notifications/DatabaseNotificationPreferences.php';

DatabaseManager::resetInstance();
$db = DatabaseManager::getInstance();
$preferences = new DatabaseNotificationPreferences($db);
$channelConfigCountBefore = (int) $db->fetchValue('SELECT COUNT(*) FROM notification_channel_configs');

$summary = $preferences->summaryForUser($userA);
$defaultSubscription = notificationPreferencesFind($summary, $category);
notificationPreferencesMysqlAssert($defaultSubscription['in_app_enabled'] === true, 'Встроенный центр должен быть включён по умолчанию');
notificationPreferencesMysqlAssert($defaultSubscription['external_enabled'] === false, 'Внешняя доставка должна быть выключена до явной подписки');
notificationPreferencesMysqlAssert($defaultSubscription['channels']['email'] === false, 'E-mail должен быть выключен до явной подписки');
notificationPreferencesMysqlAssert($summary['channels']['email']['supported'] === true, 'E-mail должен быть поддерживаемым каналом');
notificationPreferencesMysqlAssert($summary['channels']['email']['configured'] === true, 'Глобальный SMTP должен считаться настроенным');
notificationPreferencesMysqlAssert($summary['channels']['email']['destination_ready'] === true, 'Корректный E-mail пользователя должен быть готов к доставке');
notificationPreferencesMysqlAssert($summary['channels']['email']['available'] === true, 'E-mail должен быть доступен при SMTP и адресе пользователя');
notificationPreferencesMysqlAssert($summary['channels']['browser']['supported'] === false, 'Browser Push пока не должен считаться поддерживаемым адаптером');

$encodedSummary = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
foreach (['smtp.preferences.test', 'PREFERENCES_SMTP_SECRET', 'secret_ref', 'config_json'] as $secretFragment) {
    notificationPreferencesMysqlAssert(
        !str_contains($encodedSummary, $secretFragment),
        'Пользовательский ответ не должен раскрывать серверную конфигурацию: ' . $secretFragment
    );
}

$result = $preferences->updateForUser($userA, [
    'category' => $category,
    'channels' => ['email' => true],
]);
notificationPreferencesMysqlAssert($result['subscription']['external_enabled'] === true, 'Включение канала должно автоматически включать внешнюю доставку');
notificationPreferencesMysqlAssert($result['subscription']['channels']['email'] === true, 'E-mail-подписка должна сохраниться');
notificationPreferencesMysqlAssert($result['subscription']['effective_channels']['email'] === true, 'Доступный E-mail должен стать эффективным каналом');

$row = $db->fetchOne(
    'SELECT enabled, in_app_enabled, email_enabled, browser_enabled, telegram_enabled, webhook_enabled, sms_enabled '
    . 'FROM notification_subscriptions WHERE user_id = :user_id AND category = :category',
    [':user_id' => $userA, ':category' => $category]
);
notificationPreferencesMysqlAssert((int) ($row['enabled'] ?? 0) === 1, 'Внешняя подписка должна быть активна в БД');
notificationPreferencesMysqlAssert((int) ($row['in_app_enabled'] ?? 0) === 1, 'Встроенный центр нельзя отключить пользовательским API');
notificationPreferencesMysqlAssert((int) ($row['email_enabled'] ?? 0) === 1, 'Флаг E-mail должен быть сохранён');
notificationPreferencesMysqlAssert((int) ($row['browser_enabled'] ?? 0) === 0, 'Неподдерживаемый Browser Push не должен активироваться');
notificationPreferencesMysqlAssert((int) ($row['telegram_enabled'] ?? 0) === 0, 'Неподдерживаемый Telegram не должен активироваться');
notificationPreferencesMysqlAssert((int) ($row['webhook_enabled'] ?? 0) === 0, 'Неподдерживаемый Webhook не должен активироваться');
notificationPreferencesMysqlAssert((int) ($row['sms_enabled'] ?? 0) === 0, 'Неподдерживаемый SMS не должен активироваться');

$queueEvent = notificationPreferencesInsertEvent($mysqli, 'preferences-queue-enabled', $category);
$queueNotification = notificationPreferencesInsertNotification($mysqli, $queueEvent, $userA, 'Очередь включена');
$queue = new DatabaseNotificationDeliveryQueue($db);
$jobIds = $queue->enqueueForEvent(
    new NotificationEvent(
        'calendar.reminder_due',
        1,
        'preferences-queue-enabled-runtime',
        'calendar',
        $category,
        NotificationEvent::IMPORTANCE_NORMAL,
        'Очередь включена',
        'Текст',
        [$userA]
    ),
    [$userA => $queueNotification]
);
notificationPreferencesMysqlAssert(count($jobIds) === 1, 'Активная E-mail-подписка должна создать одно задание внешней доставки');

$preferences->updateForUser($userA, [
    'category' => $category,
    'external_enabled' => false,
]);
$summaryDisabled = $preferences->summaryForUser($userA);
$disabledSubscription = notificationPreferencesFind($summaryDisabled, $category);
notificationPreferencesMysqlAssert($disabledSubscription['channels']['email'] === true, 'Выключение master-флага не должно стирать выбор E-mail');
notificationPreferencesMysqlAssert($disabledSubscription['effective_channels']['email'] === false, 'При выключенном master-флаге E-mail не должен быть эффективным');

$disabledEvent = notificationPreferencesInsertEvent($mysqli, 'preferences-queue-disabled', $category);
$disabledNotification = notificationPreferencesInsertNotification($mysqli, $disabledEvent, $userA, 'Очередь выключена');
$disabledJobs = $queue->enqueueForEvent(
    new NotificationEvent(
        'calendar.reminder_due',
        1,
        'preferences-queue-disabled-runtime',
        'calendar',
        $category,
        NotificationEvent::IMPORTANCE_NORMAL,
        'Очередь выключена',
        'Текст',
        [$userA]
    ),
    [$userA => $disabledNotification]
);
notificationPreferencesMysqlAssert($disabledJobs === [], 'Выключенная внешняя подписка не должна создавать задания доставки');

$db->execute(
    'UPDATE notification_subscriptions SET in_app_enabled = 0, browser_enabled = 1 '
    . 'WHERE user_id = :user_id AND category = :category',
    [':user_id' => $userA, ':category' => $category]
);
$preferences->updateForUser($userA, [
    'category' => $category,
    'external_enabled' => true,
]);
$normalized = $db->fetchOne(
    'SELECT in_app_enabled, browser_enabled FROM notification_subscriptions '
    . 'WHERE user_id = :user_id AND category = :category',
    [':user_id' => $userA, ':category' => $category]
);
notificationPreferencesMysqlAssert((int) ($normalized['in_app_enabled'] ?? 0) === 1, 'API должен восстанавливать обязательный встроенный канал');
notificationPreferencesMysqlAssert((int) ($normalized['browser_enabled'] ?? 0) === 0, 'API должен сбрасывать неподдерживаемый внешний канал');

$unsupportedRejected = false;
try {
    $preferences->updateForUser($userA, [
        'category' => $category,
        'channels' => ['browser' => true],
    ]);
} catch (InvalidArgumentException) {
    $unsupportedRejected = true;
}
notificationPreferencesMysqlAssert($unsupportedRejected, 'Нельзя включить канал без реализованного адаптера');

$unknownFieldRejected = false;
try {
    $preferences->updateForUser($userA, [
        'category' => $category,
        'external_enabled' => true,
        'secret_ref' => 'env:SHOULD_NOT_BE_ACCEPTED',
    ]);
} catch (InvalidArgumentException) {
    $unknownFieldRejected = true;
}
notificationPreferencesMysqlAssert($unknownFieldRejected, 'Пользовательский API должен отклонять неизвестные и секретные поля');

$preferences->updateForUser($userA, [
    'category' => $category,
    'external_enabled' => true,
    'channels' => ['email' => true],
]);
$preferences->updateForUser($userA, [
    'category' => $category,
    'external_enabled' => true,
    'channels' => ['email' => true],
]);
$rowCount = (int) $db->fetchValue(
    'SELECT COUNT(*) FROM notification_subscriptions WHERE user_id = :user_id AND category = :category',
    [':user_id' => $userA, ':category' => $category]
);
notificationPreferencesMysqlAssert($rowCount === 1, 'Повторное сохранение подписки не должно создавать дубликат');

$preferences->updateForUser($userB, [
    'category' => $category,
    'channels' => ['email' => true],
]);
$summaryB = $preferences->summaryForUser($userB);
$subscriptionB = notificationPreferencesFind($summaryB, $category);
notificationPreferencesMysqlAssert($summaryB['channels']['email']['configured'] === true, 'SMTP-конфигурация должна оставаться общей для пользователя B');
notificationPreferencesMysqlAssert($summaryB['channels']['email']['destination_ready'] === false, 'Некорректный E-mail пользователя не должен считаться готовым');
notificationPreferencesMysqlAssert($summaryB['channels']['email']['available'] === false, 'E-mail без корректного адреса не должен быть доступен');
notificationPreferencesMysqlAssert($subscriptionB['channels']['email'] === true, 'Предпочтение пользователя должно храниться независимо от текущей готовности адреса');
notificationPreferencesMysqlAssert($subscriptionB['effective_channels']['email'] === false, 'Некорректный адрес не должен становиться эффективным каналом');

$channelConfigCountAfter = (int) $db->fetchValue('SELECT COUNT(*) FROM notification_channel_configs');
notificationPreferencesMysqlAssert(
    $channelConfigCountAfter === $channelConfigCountBefore,
    'Пользовательские настройки не должны создавать или изменять серверные конфигурации каналов'
);

DatabaseManager::resetInstance();
$mysqli->close();
fwrite(STDOUT, "[OK] MySQL-контракт пользовательских настроек уведомлений 1.1.0\n");
