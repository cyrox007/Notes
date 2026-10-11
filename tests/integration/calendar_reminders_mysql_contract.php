<?php

declare(strict_types=1);

use Core\DatabaseManager;
use Modules\Calendar\Services\CalendarReminderPublisher;
use Modules\Calendar\Services\CalendarReminderScheduler;

$root = dirname(__DIR__, 2);
$dbHost = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$dbPort = (int) (getenv('TEST_DB_PORT') ?: 3306);
$dbName = getenv('TEST_DB_NAME') ?: 'workspace_test';
$dbUser = getenv('TEST_DB_USER') ?: 'workspace';
$dbPass = getenv('TEST_DB_PASS') ?: 'workspace';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$mysqli->set_charset('utf8mb4');

function calendarMysqlAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function calendarMysqlApplySql(mysqli $db, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) {
        throw new RuntimeException('Не удалось прочитать SQL: ' . $path);
    }

    $statements = preg_split('/;\s*(?:\R|$)/', $sql) ?: [];
    foreach ($statements as $index => $statement) {
        $statement = trim($statement);
        if ($statement === '' || str_starts_with($statement, '--')) {
            $statement = preg_replace('/^(?:--[^\r\n]*(?:\R|$))+/', '', $statement) ?? '';
            $statement = trim($statement);
        }
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

function calendarMysqlInsertUser(mysqli $db, string $uid, string $username, string $email, int $active): int
{
    $stmt = $db->prepare(
        "INSERT INTO users (uid, username, email, password_hash, firstname, lastname, role, is_active, account_status)\n"
        . "VALUES (?, ?, ?, 'hash', 'Тест', 'Calendar', 888, ?, 'active')"
    );
    $stmt->bind_param('sssi', $uid, $username, $email, $active);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

function calendarMysqlInsertEvent(
    mysqli $db,
    string $uid,
    int $userId,
    string $title,
    string $timezone,
    string $startsAt,
    ?string $recurrence = null
): int {
    $stmt = $db->prepare(
        'INSERT INTO calendar_events '
        . '(uid, owner_user_id, title, timezone, starts_at_utc, recurrence_rule, status) '
        . "VALUES (?, ?, ?, ?, ?, ?, 'active')"
    );
    $stmt->bind_param('sissss', $uid, $userId, $title, $timezone, $startsAt, $recurrence);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

calendarMysqlApplySql($mysqli, $root . '/database/core_identity_schema.sql');
calendarMysqlApplySql($mysqli, $root . '/database/migrations/20261010_notifications_foundation.sql');
calendarMysqlApplySql($mysqli, $root . '/database/migrations/20261011_calendar_reminders.sql');

$mysqli->query('SET FOREIGN_KEY_CHECKS=0');
foreach ([
    'notification_delivery_attempts',
    'notification_delivery_jobs',
    'notifications',
    'notification_events',
    'notification_subscriptions',
    'notification_channel_configs',
    'calendar_reminders',
    'calendar_events',
] as $table) {
    $mysqli->query('DELETE FROM `' . $table . '`');
}
$mysqli->query("DELETE FROM users WHERE username LIKE 'calendar-reminder-test-%'");
$mysqli->query("UPDATE calendar_runtime_state SET last_http_run_at = NULL WHERE state_key = 'reminder_http_fallback'");
$mysqli->query('SET FOREIGN_KEY_CHECKS=1');

$activeUser = calendarMysqlInsertUser(
    $mysqli,
    '30000000-0000-4000-8000-000000000001',
    'calendar-reminder-test-active',
    'calendar-active@example.test',
    1
);
$inactiveUser = calendarMysqlInsertUser(
    $mysqli,
    '30000000-0000-4000-8000-000000000002',
    'calendar-reminder-test-inactive',
    'calendar-inactive@example.test',
    0
);

$mysqli->query(
    "INSERT INTO notification_channel_configs "
    . "(channel, owner_user_id, owner_scope, label, enabled, config_json, secret_ref) VALUES "
    . "('email', NULL, 0, 'Calendar test SMTP', 1, "
    . "JSON_OBJECT('host', 'smtp.calendar.test', 'port', 587, 'encryption', 'starttls', "
    . "'sender_email', 'no-reply@example.test'), 'env:CALENDAR_TEST_SMTP_SECRET')"
);
$stmt = $mysqli->prepare(
    "INSERT INTO notification_subscriptions "
    . "(user_id, category, enabled, in_app_enabled, email_enabled) VALUES (?, 'calendar.reminders', 1, 1, 1)"
);
$stmt->bind_param('i', $activeUser);
$stmt->execute();
$stmt->close();

putenv('DBDRIVER=mysql');
putenv('DBHOST=' . $dbHost);
putenv('DBPORT=' . $dbPort);
putenv('DBNAME=' . $dbName);
putenv('DBUSER=' . $dbUser);
putenv('DBPASS=' . $dbPass);

require_once $root . '/core/Config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/app/services/notifications/NotificationService.php';
require_once $root . '/modules/calendar/services/CalendarReminderScheduler.php';
require_once $root . '/modules/calendar/services/CalendarReminderPublisher.php';

DatabaseManager::resetInstance();
$db = DatabaseManager::getInstance();
$scheduler = new CalendarReminderScheduler($db);
$publisher = new CalendarReminderPublisher($db);

$singleUid = '40000000-0000-4000-8000-000000000001';
$singleEvent = calendarMysqlInsertEvent(
    $mysqli,
    $singleUid,
    $activeUser,
    'Проверка одиночного напоминания',
    'Europe/Stockholm',
    '2026-10-11 10:00:00'
);
$singleIds = $scheduler->scheduleOccurrence(
    $singleEvent,
    new DateTimeImmutable('2026-10-11T10:00:00Z'),
    [600]
);
calendarMysqlAssert(count($singleIds) === 1, 'Одиночный экземпляр должен создать одно напоминание');

$duplicateIds = $scheduler->scheduleOccurrence(
    $singleEvent,
    new DateTimeImmutable('2026-10-11T10:00:00Z'),
    [600, 600]
);
calendarMysqlAssert($duplicateIds === $singleIds, 'Повторное планирование должно вернуть тот же reminder id');
$singleRowCount = (int) $db->fetchValue(
    'SELECT COUNT(*) FROM calendar_reminders WHERE event_id = :event_id',
    [':event_id' => $singleEvent]
);
calendarMysqlAssert($singleRowCount === 1, 'Повторное планирование не должно создавать дубликат строки');

$firstRun = $publisher->run(20, 604800, new DateTimeImmutable('2026-10-11T09:50:00Z'));
calendarMysqlAssert($firstRun['published'] === 1 && $firstRun['failed'] === 0, 'Due-напоминание должно быть опубликовано');
$eventRow = $db->fetchOne(
    'SELECT id, idempotency_key, source_name, category, payload_json FROM notification_events '
    . 'WHERE idempotency_key LIKE :key LIMIT 1',
    [':key' => 'calendar.reminder:' . $singleUid . ':%']
);
calendarMysqlAssert(is_array($eventRow), 'NotificationService должен сохранить семантическое событие Calendar');
calendarMysqlAssert(($eventRow['source_name'] ?? null) === 'calendar', 'Источник уведомления должен быть calendar');
calendarMysqlAssert(($eventRow['category'] ?? null) === 'calendar.reminders', 'Категория должна совпадать с настройками UI');
calendarMysqlAssert(
    str_contains((string) $eventRow['idempotency_key'], ':20261011T100000Z:600'),
    'Ключ идемпотентности должен включать конкретный экземпляр серии и offset'
);
$payload = json_decode((string) $eventRow['payload_json'], true, 16, JSON_THROW_ON_ERROR);
$payloadKeys = array_keys($payload);
sort($payloadKeys, SORT_STRING);
$expectedPayloadKeys = ['event_uid', 'occurrence_start_utc', 'offset_seconds', 'timezone'];
sort($expectedPayloadKeys, SORT_STRING);
calendarMysqlAssert($payloadKeys === $expectedPayloadKeys, 'Payload Calendar должен оставаться минимальным независимо от порядка ключей MySQL JSON');

$notification = $db->fetchOne(
    'SELECT title, body, target_path FROM notifications WHERE event_id = :event_id AND recipient_user_id = :user_id LIMIT 1',
    [':event_id' => (int) $eventRow['id'], ':user_id' => $activeUser]
);
calendarMysqlAssert(is_array($notification), 'Активный владелец должен получить встроенное уведомление');
calendarMysqlAssert(str_contains((string) $notification['body'], '12:00'), 'Локальное время Europe/Stockholm должно учитывать IANA timezone');
calendarMysqlAssert(str_contains((string) $notification['body'], 'Europe/Stockholm'), 'Текст должен явно показывать timezone события');
calendarMysqlAssert(($notification['target_path'] ?? null) === '/calendar?event=' . $singleUid, 'Уведомление должно вести к событию Calendar');
$deliveryJobs = (int) $db->fetchValue(
    'SELECT COUNT(*) FROM notification_delivery_jobs WHERE notification_id = ('
    . 'SELECT id FROM notifications WHERE event_id = :event_id AND recipient_user_id = :user_id LIMIT 1) '
    . "AND channel = 'email'",
    [':event_id' => (int) $eventRow['id'], ':user_id' => $activeUser]
);
calendarMysqlAssert($deliveryJobs === 1, 'Подписка E-mail должна создать одно задание общей очереди');

$secondRun = $publisher->run(20, 604800, new DateTimeImmutable('2026-10-11T09:51:00Z'));
calendarMysqlAssert($secondRun['selected'] === 0, 'Уже опубликованное напоминание не должно выбираться повторно');

$db->execute(
    "UPDATE calendar_reminders SET state = 'pending', published_at = NULL WHERE id = :id",
    [':id' => $singleIds[0]]
);
$retryRun = $publisher->run(20, 604800, new DateTimeImmutable('2026-10-11T09:52:00Z'));
calendarMysqlAssert($retryRun['published'] === 1, 'Повтор после сбоя между publish и фиксацией Calendar должен завершиться успешно');
calendarMysqlAssert(
    (int) $db->fetchValue('SELECT COUNT(*) FROM notification_events WHERE idempotency_key = :key', [':key' => $eventRow['idempotency_key']]) === 1,
    'Повтор publisher не должен дублировать notification_event'
);
calendarMysqlAssert(
    (int) $db->fetchValue('SELECT COUNT(*) FROM notifications WHERE event_id = :event_id', [':event_id' => (int) $eventRow['id']]) === 1,
    'Повтор publisher не должен дублировать встроенное уведомление'
);
calendarMysqlAssert(
    (int) $db->fetchValue('SELECT COUNT(*) FROM notification_delivery_jobs WHERE notification_id = (SELECT id FROM notifications WHERE event_id = :event_id LIMIT 1)', [':event_id' => (int) $eventRow['id']]) === 1,
    'Повтор publisher не должен дублировать внешнее задание'
);

$recurringUid = '40000000-0000-4000-8000-000000000002';
$recurringEvent = calendarMysqlInsertEvent(
    $mysqli,
    $recurringUid,
    $activeUser,
    'Еженедельная встреча',
    'Europe/Stockholm',
    '2026-10-12 10:00:00',
    'FREQ=WEEKLY'
);
$recurringIds = $scheduler->scheduleOccurrences(
    $recurringEvent,
    [
        new DateTimeImmutable('2026-10-12T10:00:00Z'),
        new DateTimeImmutable('2026-10-19T10:00:00Z'),
    ],
    [0]
);
calendarMysqlAssert(count($recurringIds) === 2, 'Два экземпляра повторяющейся серии должны иметь два reminder id');
$recurringRun = $publisher->run(20, 1209600, new DateTimeImmutable('2026-10-19T10:00:00Z'));
calendarMysqlAssert($recurringRun['published'] === 2, 'Catch-up должен опубликовать оба конкретных экземпляра серии без дубликатов');
$recurringEvents = $db->fetchAll(
    'SELECT idempotency_key FROM notification_events WHERE idempotency_key LIKE :key ORDER BY idempotency_key',
    [':key' => 'calendar.reminder:' . $recurringUid . ':%']
);
calendarMysqlAssert(count($recurringEvents) === 2, 'Каждый экземпляр серии должен иметь отдельный idempotency key');
calendarMysqlAssert($recurringEvents[0]['idempotency_key'] !== $recurringEvents[1]['idempotency_key'], 'Ключи экземпляров серии не должны совпадать');

$staleUid = '40000000-0000-4000-8000-000000000003';
$staleEvent = calendarMysqlInsertEvent(
    $mysqli,
    $staleUid,
    $activeUser,
    'Слишком старое напоминание',
    'UTC',
    '2026-09-01 10:00:00'
);
$staleId = $scheduler->scheduleOccurrence($staleEvent, new DateTimeImmutable('2026-09-01T10:00:00Z'), [0])[0];
$staleRun = $publisher->run(20, 604800, new DateTimeImmutable('2026-10-19T10:01:00Z'));
calendarMysqlAssert($staleRun['expired'] >= 1, 'Напоминание за пределами catch-up окна должно стать expired');
calendarMysqlAssert(
    $db->fetchValue('SELECT state FROM calendar_reminders WHERE id = :id', [':id' => $staleId]) === 'expired',
    'Старое напоминание не должно оставаться pending'
);
calendarMysqlAssert(
    (int) $db->fetchValue('SELECT COUNT(*) FROM notification_events WHERE idempotency_key LIKE :key', [':key' => 'calendar.reminder:' . $staleUid . ':%']) === 0,
    'Слишком старое напоминание не должно публиковаться'
);

$cancelledUid = '40000000-0000-4000-8000-000000000004';
$cancelledEvent = calendarMysqlInsertEvent(
    $mysqli,
    $cancelledUid,
    $activeUser,
    'Отменённое событие',
    'UTC',
    '2026-10-20 10:00:00'
);
$cancelledId = $scheduler->scheduleOccurrence($cancelledEvent, new DateTimeImmutable('2026-10-20T10:00:00Z'), [0])[0];
$db->execute("UPDATE calendar_events SET status = 'cancelled' WHERE id = :id", [':id' => $cancelledEvent]);
$publisher->run(20, 604800, new DateTimeImmutable('2026-10-20T10:00:00Z'));
calendarMysqlAssert(
    $db->fetchValue('SELECT state FROM calendar_reminders WHERE id = :id', [':id' => $cancelledId]) === 'expired',
    'Напоминание отменённого события должно безопасно истечь'
);
calendarMysqlAssert(
    (int) $db->fetchValue('SELECT COUNT(*) FROM notification_events WHERE idempotency_key LIKE :key', [':key' => 'calendar.reminder:' . $cancelledUid . ':%']) === 0,
    'Отменённое событие не должно создавать уведомление'
);

$inactiveUid = '40000000-0000-4000-8000-000000000005';
$inactiveEvent = calendarMysqlInsertEvent(
    $mysqli,
    $inactiveUid,
    $inactiveUser,
    'Напоминание неактивного пользователя',
    'UTC',
    '2026-10-20 11:00:00'
);
$inactiveReminder = $scheduler->scheduleOccurrence($inactiveEvent, new DateTimeImmutable('2026-10-20T11:00:00Z'), [0])[0];
$inactiveRun = $publisher->run(20, 604800, new DateTimeImmutable('2026-10-20T11:00:00Z'));
calendarMysqlAssert($inactiveRun['published'] === 1, 'Семантическое событие должно считаться обработанным даже без допустимого получателя');
$inactiveEventId = (int) $db->fetchValue(
    'SELECT id FROM notification_events WHERE idempotency_key LIKE :key LIMIT 1',
    [':key' => 'calendar.reminder:' . $inactiveUid . ':%']
);
calendarMysqlAssert($inactiveEventId > 0, 'Событие неактивного владельца должно сохраняться для идемпотентности');
calendarMysqlAssert(
    (int) $db->fetchValue('SELECT COUNT(*) FROM notifications WHERE event_id = :event_id', [':event_id' => $inactiveEventId]) === 0,
    'Неактивный получатель не должен получить встроенное уведомление'
);
calendarMysqlAssert(
    $db->fetchValue('SELECT state FROM calendar_reminders WHERE id = :id', [':id' => $inactiveReminder]) === 'published',
    'Напоминание не должно бесконечно повторяться для недоступного получателя'
);

$lockResult = $mysqli->query("SELECT GET_LOCK('workspace.calendar.reminders.publisher', 0) AS locked")->fetch_assoc();
calendarMysqlAssert((int) ($lockResult['locked'] ?? 0) === 1, 'Тест должен захватить именованную блокировку');
$lockedRun = $publisher->run(20, 604800, new DateTimeImmutable('2026-10-20T12:00:00Z'));
calendarMysqlAssert($lockedRun['locked'] === true && $lockedRun['selected'] === 0, 'Второй publisher не должен обрабатывать очередь параллельно');
$mysqli->query("SELECT RELEASE_LOCK('workspace.calendar.reminders.publisher')");

$db->execute(
    'UPDATE calendar_runtime_state SET last_http_run_at = NULL WHERE state_key = :state_key',
    [':state_key' => 'reminder_http_fallback']
);
$httpFirst = $publisher->runHttpFallback(new DateTimeImmutable('2026-10-20T12:10:00Z'));
$httpSecond = $publisher->runHttpFallback(new DateTimeImmutable('2026-10-20T12:10:00Z'));
calendarMysqlAssert($httpFirst['throttled'] === false, 'Первый HTTP fallback должен выполнить bounded-проход');
calendarMysqlAssert($httpSecond['throttled'] === true, 'Повторный HTTP fallback в ту же минуту должен быть подавлен');

$mysqli->close();
DatabaseManager::resetInstance();

echo "[OK] MySQL-контракт напоминаний Calendar 1.1.0\n";
