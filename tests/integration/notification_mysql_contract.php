<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$dbHost = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$dbPort = (int) (getenv('TEST_DB_PORT') ?: 3306);
$dbName = getenv('TEST_DB_NAME') ?: 'workspace_test';
$dbUser = getenv('TEST_DB_USER') ?: 'workspace';
$dbPass = getenv('TEST_DB_PASS') ?: 'workspace';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$mysqli->set_charset('utf8mb4');

function notificationMysqlAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function notificationApplySql(mysqli $db, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) {
        throw new RuntimeException('Не удалось прочитать SQL: ' . $path);
    }

    $db->multi_query($sql);
    do {
        $result = $db->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        if (!$db->more_results()) {
            break;
        }
        $db->next_result();
    } while (true);
}

notificationApplySql($mysqli, $root . '/database/core_identity_schema.sql');
notificationApplySql($mysqli, $root . '/database/migrations/20261010_notifications_foundation.sql');

$insertUser = $mysqli->prepare(
    'INSERT INTO users (
        uid, username, email, password_hash, firstname, lastname, role,
        is_active, account_status, deletion_requested_at, anonymized_at
     ) VALUES (?, ?, ?, ?, \'Тест\', \'Пользователь\', 888, ?, ?, ?, ?)'
);

$users = [
    ['00000000-0000-4000-8000-000000000001', 'active-user', 'active@example.test', 'hash', 1, 'active', null, null],
    ['00000000-0000-4000-8000-000000000002', 'inactive-user', 'inactive@example.test', 'hash', 0, 'inactive', null, null],
    ['00000000-0000-4000-8000-000000000003', 'deleting-user', 'deleting@example.test', 'hash', 1, 'active', '2026-10-11 00:00:00', null],
    ['00000000-0000-4000-8000-000000000004', 'anonymous-user', 'anonymous@example.test', 'hash', 1, 'active', null, '2026-10-11 00:00:00'],
];
$userIds = [];
foreach ($users as $user) {
    [$uid, $username, $email, $hash, $active, $status, $deletionRequestedAt, $anonymizedAt] = $user;
    $insertUser->bind_param(
        'ssssisss',
        $uid,
        $username,
        $email,
        $hash,
        $active,
        $status,
        $deletionRequestedAt,
        $anonymizedAt
    );
    $insertUser->execute();
    $userIds[] = (int) $mysqli->insert_id;
}
$insertUser->close();

[$activeId, $inactiveId, $deletingId, $anonymousId] = $userIds;

$mysqli->query(
    "INSERT INTO notification_channel_configs (channel, owner_user_id, label, enabled, config_json, secret_ref)
     VALUES ('email', NULL, 'Основной SMTP', 1, JSON_OBJECT('host', 'smtp.example.test'), 'smtp:test')"
);
$globalConfigId = (int) $mysqli->insert_id;

$duplicateRejected = false;
try {
    $mysqli->query(
        "INSERT INTO notification_channel_configs (channel, owner_user_id, label, enabled, config_json)
         VALUES ('email', NULL, 'Основной SMTP', 1, JSON_OBJECT())"
    );
} catch (mysqli_sql_exception $e) {
    $duplicateRejected = $e->getCode() === 1062;
}
notificationMysqlAssert($duplicateRejected, 'MySQL должен запрещать дубликат глобальной конфигурации канала');

$mysqli->query(
    "INSERT INTO notification_channel_configs (channel, owner_user_id, label, enabled, config_json)
     VALUES ('email', {$activeId}, 'Основной SMTP', 1, JSON_OBJECT('host', 'user-smtp.example.test'))"
);
$userConfigId = (int) $mysqli->insert_id;
notificationMysqlAssert($userConfigId > $globalConfigId, 'Пользовательская конфигурация с тем же label должна быть допустима');

$mysqli->query(
    "INSERT INTO notification_subscriptions (user_id, category, enabled, in_app_enabled, email_enabled)
     VALUES ({$activeId}, 'calendar.reminders', 1, 1, 1)"
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
require_once $root . '/app/services/notifications/DatabaseNotificationRecipientResolver.php';
require_once $root . '/app/services/notifications/DatabaseNotificationDeliveryQueue.php';
require_once $root . '/app/services/notifications/DatabaseNotificationStore.php';
require_once $root . '/app/services/notifications/NotificationService.php';

\Core\DatabaseManager::resetInstance();
$db = \Core\DatabaseManager::getInstance();
$resolver = new \App\Services\Notifications\DatabaseNotificationRecipientResolver($db);
$eligible = $resolver->eligibleUserIds([$activeId, $inactiveId, $deletingId, $anonymousId]);
notificationMysqlAssert($eligible === [$activeId], 'Resolver должен оставить только активного и не удаляемого пользователя');

$service = new \App\Services\Notifications\NotificationService();
$event = new \App\Services\Notifications\NotificationEvent(
    name: 'calendar.reminder_due',
    version: 1,
    idempotencyKey: 'mysql-contract-reminder-1',
    source: 'calendar',
    category: 'calendar.reminders',
    importance: \App\Services\Notifications\NotificationEvent::IMPORTANCE_HIGH,
    title: 'Напоминание календаря',
    body: 'Проверка очереди уведомлений',
    recipientUserIds: [$activeId, $inactiveId, $deletingId, $anonymousId],
    payload: ['calendar_event_id' => 501],
    targetPath: '/calendar/events/501'
);

$first = $service->publish($event);
$second = $service->publish($event);
notificationMysqlAssert($first === $second, 'Повторная публикация должна возвращать те же идентификаторы');
notificationMysqlAssert(count($first['notification_ids']) === 1, 'Встроенное уведомление должно быть создано только допустимому получателю');
notificationMysqlAssert((int) $mysqli->query('SELECT COUNT(*) AS c FROM notification_events')->fetch_assoc()['c'] === 1, 'Событие должно дедуплицироваться');
notificationMysqlAssert((int) $mysqli->query('SELECT COUNT(*) AS c FROM notifications')->fetch_assoc()['c'] === 1, 'Уведомление получателя должно дедуплицироваться');
notificationMysqlAssert((int) $mysqli->query('SELECT COUNT(*) AS c FROM notification_delivery_jobs')->fetch_assoc()['c'] === 1, 'Внешнее задание должно дедуплицироваться');

$jobRow = $mysqli->query('SELECT channel_config_id, recipient_ref, state FROM notification_delivery_jobs LIMIT 1')->fetch_assoc();
notificationMysqlAssert((int) $jobRow['channel_config_id'] === $userConfigId, 'Пользовательская конфигурация должна иметь приоритет над глобальной');
notificationMysqlAssert($jobRow['recipient_ref'] === 'user:' . $activeId, 'Очередь не должна дублировать E-mail в recipient_ref');
notificationMysqlAssert($jobRow['state'] === 'pending', 'Новое задание должно начинаться в pending');

$queue = new \App\Services\Notifications\DatabaseNotificationDeliveryQueue($db);
$claimed = $queue->claimBatch('mysql-worker-01', 10, 300);
notificationMysqlAssert(count($claimed) === 1, 'Worker должен захватить одно готовое задание');
notificationMysqlAssert(($claimed[0]['recipient_eligible'] ?? false) === true, 'Получатель должен повторно проверяться при захвате');
notificationMysqlAssert(($claimed[0]['channel_enabled'] ?? false) === true, 'Канал должен повторно проверяться при захвате');
$jobId = (int) $claimed[0]['id'];
$queue->markFailed($jobId, 'mysql-worker-01', 'smtp_temporary', 'Временная тестовая ошибка', true);

$retryRow = $mysqli->query("SELECT state, attempts, locked_by, last_result_code FROM notification_delivery_jobs WHERE id = {$jobId}")->fetch_assoc();
notificationMysqlAssert($retryRow['state'] === 'retry', 'Временная ошибка должна переводить задание в retry');
notificationMysqlAssert((int) $retryRow['attempts'] === 1, 'Попытка должна учитываться при захвате задания');
notificationMysqlAssert($retryRow['locked_by'] === null, 'После ошибки блокировка worker должна сниматься');
notificationMysqlAssert($retryRow['last_result_code'] === 'smtp_temporary', 'Код результата должен сохраняться');
notificationMysqlAssert((int) $mysqli->query("SELECT COUNT(*) AS c FROM notification_delivery_attempts WHERE job_id = {$jobId}")->fetch_assoc()['c'] === 1, 'История попытки должна сохраняться');

$db->execute(
    'UPDATE notification_delivery_jobs SET next_attempt_at = :ready WHERE id = :id',
    [':ready' => '2000-01-01 00:00:00', ':id' => $jobId]
);
$claimedAgain = $queue->claimBatch('mysql-worker-02', 10, 300);
notificationMysqlAssert(count($claimedAgain) === 1, 'Retry должен снова становиться доступным');
$queue->markFailed($jobId, 'mysql-worker-02', 'recipient_blocked', 'Окончательная тестовая ошибка', false);
$failedState = (string) $mysqli->query("SELECT state FROM notification_delivery_jobs WHERE id = {$jobId}")->fetch_assoc()['state'];
notificationMysqlAssert($failedState === 'failed', 'Неповторяемая ошибка должна завершать задание');
notificationMysqlAssert((int) $mysqli->query("SELECT COUNT(*) AS c FROM notification_delivery_attempts WHERE job_id = {$jobId}")->fetch_assoc()['c'] === 2, 'Каждая завершённая попытка должна иметь отдельную запись');

\Core\DatabaseManager::resetInstance();
$mysqli->close();
fwrite(STDOUT, "[OK] MySQL-контракт фундамента уведомлений 1.1.0\n");
