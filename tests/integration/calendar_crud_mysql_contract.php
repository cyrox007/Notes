<?php

declare(strict_types=1);

use Core\DatabaseManager;
use Modules\Calendar\Services\CalendarEventService;
use Modules\Calendar\Services\CalendarRecurrence;
use Modules\Calendar\Services\CalendarReminderPlanner;
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

function calendarCrudMysqlAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function calendarCrudApplySql(mysqli $db, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) {
        throw new RuntimeException('Не удалось прочитать SQL: ' . $path);
    }
    if (!$db->multi_query($sql)) {
        throw new RuntimeException('Не удалось применить ' . basename($path) . ': ' . $db->error);
    }
    do {
        $result = $db->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        if (!$db->more_results()) {
            break;
        }
    } while ($db->next_result());
    if ($db->errno !== 0) {
        throw new RuntimeException('Ошибка SQL в ' . basename($path) . ': ' . $db->error);
    }
}

function calendarCrudInsertUser(mysqli $db, string $uid, string $username): int
{
    $email = $username . '@example.test';
    $stmt = $db->prepare(
        "INSERT INTO users (uid, username, email, password_hash, firstname, lastname, role, is_active, account_status) "
        . "VALUES (?, ?, ?, 'hash', 'Тест', 'Calendar', 888, 1, 'active')"
    );
    $stmt->bind_param('sss', $uid, $username, $email);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

calendarCrudApplySql($mysqli, $root . '/database/core_identity_schema.sql');
calendarCrudApplySql($mysqli, $root . '/database/access_control_schema.sql');
calendarCrudApplySql($mysqli, $root . '/database/migrations/20261010_notifications_foundation.sql');
calendarCrudApplySql($mysqli, $root . '/database/migrations/20261011_calendar_reminders.sql');
calendarCrudApplySql($mysqli, $root . '/database/migrations/20261011_calendar_crud.sql');

$mysqli->query('SET FOREIGN_KEY_CHECKS=0');
foreach ([
    'notification_delivery_attempts', 'notification_delivery_jobs', 'notifications', 'notification_events',
    'notification_subscriptions', 'notification_channel_configs', 'calendar_reminders',
    'calendar_event_reminder_offsets', 'calendar_events', 'user_roles'
] as $table) {
    $mysqli->query('DELETE FROM `' . $table . '`');
}
$mysqli->query("DELETE FROM users WHERE username LIKE 'calendar-crud-test-%'");
$mysqli->query('SET FOREIGN_KEY_CHECKS=1');

$userA = calendarCrudInsertUser($mysqli, '51000000-0000-4000-8000-000000000001', 'calendar-crud-test-a');
$userB = calendarCrudInsertUser($mysqli, '51000000-0000-4000-8000-000000000002', 'calendar-crud-test-b');

$calendarPermission = (int) $mysqli->query("SELECT COUNT(*) AS c FROM permissions WHERE code='calendar.use' AND module_id='calendar'")->fetch_assoc()['c'];
calendarCrudMysqlAssert($calendarPermission === 1, 'Миграция должна создать calendar.use');
$stmt = $mysqli->prepare(
    "SELECT COUNT(*) AS c FROM user_roles ur "
    . "JOIN role_permissions rp ON rp.role_id=ur.role_id "
    . "JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=? AND p.code='calendar.use'"
);
$stmt->bind_param('i', $userA);
$stmt->execute();
calendarCrudMysqlAssert((int) $stmt->get_result()->fetch_assoc()['c'] === 1, 'Базовая роль должна получить calendar.use');
$stmt->close();

putenv('DBDRIVER=mysql');
putenv('DBHOST=' . $dbHost);
putenv('DBPORT=' . $dbPort);
putenv('DBNAME=' . $dbName);
putenv('DBUSER=' . $dbUser);
putenv('DBPASS=' . $dbPass);

require_once $root . '/core/Config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/core/Uuid.php';
require_once $root . '/modules/calendar/services/CalendarRecurrence.php';
require_once $root . '/modules/calendar/services/CalendarReminderScheduler.php';
require_once $root . '/modules/calendar/services/CalendarReminderPlanner.php';
require_once $root . '/modules/calendar/services/CalendarEventService.php';

DatabaseManager::resetInstance();
$db = DatabaseManager::getInstance();
$service = new CalendarEventService(
    $db,
    new CalendarRecurrence(),
    new CalendarReminderPlanner($db, new CalendarReminderScheduler($db), new CalendarRecurrence())
);

$created = $service->createForUser($userA, [
    'title' => 'Еженедельная планёрка',
    'description' => 'Проверка CRUD и DST',
    'location' => 'Переговорная 2',
    'all_day' => false,
    'timezone' => 'Europe/Stockholm',
    'starts_at' => '2026-10-24T09:00',
    'ends_at' => '2026-10-24T10:00',
    'recurrence' => [
        'frequency' => 'weekly',
        'interval' => 1,
        'weekdays' => ['SA'],
        'until' => '2026-11-07',
    ],
    'reminder_offsets' => [900, 3600],
]);
calendarCrudMysqlAssert(isset($created['uid']) && is_string($created['uid']), 'Создание должно вернуть uid');
calendarCrudMysqlAssert($created['local_start'] === '2026-10-24T09:00:00', 'API должен вернуть локальное начало события');
calendarCrudMysqlAssert($created['reminder_offsets'] === [900, 3600], 'Смещения должны сохраняться в сортированном виде');

$uid = (string) $created['uid'];
$rows = $service->listForUser(
    $userA,
    new DateTimeImmutable('2026-10-23T00:00:00Z'),
    new DateTimeImmutable('2026-11-08T00:00:00Z')
)['events'];
calendarCrudMysqlAssert(count($rows) === 3, 'Еженедельная серия должна вернуть три экземпляра');
calendarCrudMysqlAssert(substr((string) $rows[0]['local_start'], 11, 5) === '09:00', 'Первый экземпляр должен остаться в 09:00 local');
calendarCrudMysqlAssert(substr((string) $rows[1]['local_start'], 11, 5) === '09:00', 'DST не должен сдвигать локальное время серии');
calendarCrudMysqlAssert(substr((string) $rows[2]['local_start'], 11, 5) === '09:00', 'Последний экземпляр должен остаться в 09:00 local');
calendarCrudMysqlAssert(substr((string) $rows[0]['starts_at_utc'], 11, 8) === '07:00:00', 'До смены DST 09:00 Stockholm должно быть 07:00 UTC');
calendarCrudMysqlAssert(substr((string) $rows[1]['starts_at_utc'], 11, 8) === '08:00:00', 'После смены DST 09:00 Stockholm должно быть 08:00 UTC');

$otherRows = $service->listForUser(
    $userB,
    new DateTimeImmutable('2026-10-23T00:00:00Z'),
    new DateTimeImmutable('2026-11-08T00:00:00Z')
)['events'];
calendarCrudMysqlAssert($otherRows === [], 'Пользователь не должен видеть чужие события');
$foreignRejected = false;
try {
    $service->detailForUser($userB, $uid);
} catch (InvalidArgumentException) {
    $foreignRejected = true;
}
calendarCrudMysqlAssert($foreignRejected, 'Чужой uid должен быть неотличим от отсутствующего события');

$eventId = (int) $db->fetchValue('SELECT id FROM calendar_events WHERE uid = :uid', [':uid' => $uid]);
calendarCrudMysqlAssert(
    (int) $db->fetchValue('SELECT COUNT(*) FROM calendar_reminders WHERE event_id = :id', [':id' => $eventId]) >= 6,
    'Создание повторяющегося события должно сразу спланировать ближайшие напоминания'
);

$updated = $service->updateForUser($userA, $uid, [
    'title' => 'Обновлённая планёрка',
    'description' => 'Новая версия события',
    'location' => 'Онлайн',
    'all_day' => false,
    'timezone' => 'Europe/Stockholm',
    'starts_at' => '2026-10-30T14:30',
    'ends_at' => '2026-10-30T15:15',
    'recurrence' => [
        'frequency' => 'daily',
        'interval' => 1,
        'weekdays' => [],
        'until' => '2026-11-02',
    ],
    'reminder_offsets' => [300],
]);
calendarCrudMysqlAssert($updated['title'] === 'Обновлённая планёрка', 'Обновление должно изменить название');
calendarCrudMysqlAssert($updated['reminder_offsets'] === [300], 'Обновление должно заменить набор напоминаний');
calendarCrudMysqlAssert(
    (int) $db->fetchValue(
        "SELECT COUNT(*) FROM calendar_reminders WHERE event_id = :id AND state='pending' AND offset_seconds <> 300",
        [':id' => $eventId]
    ) === 0,
    'После обновления не должны оставаться старые ожидающие offsets'
);
$daily = $service->listForUser(
    $userA,
    new DateTimeImmutable('2026-10-29T00:00:00Z'),
    new DateTimeImmutable('2026-11-03T23:59:59Z')
)['events'];
calendarCrudMysqlAssert(count($daily) === 4, 'Ежедневное повторение до 2 ноября должно дать четыре экземпляра');

$service->cancelForUser($userA, $uid);
calendarCrudMysqlAssert(
    $db->fetchValue('SELECT status FROM calendar_events WHERE id = :id', [':id' => $eventId]) === 'cancelled',
    'Удаление должно мягко отменить событие'
);
calendarCrudMysqlAssert(
    (int) $db->fetchValue("SELECT COUNT(*) FROM calendar_reminders WHERE event_id=:id AND state='pending'", [':id' => $eventId]) === 0,
    'После удаления не должно оставаться ожидающих напоминаний'
);
calendarCrudMysqlAssert(
    $service->listForUser($userA, new DateTimeImmutable('2026-10-29T00:00:00Z'), new DateTimeImmutable('2026-11-03T23:59:59Z'))['events'] === [],
    'Отменённое событие не должно возвращаться в календарь'
);

fwrite(STDOUT, "[OK] Calendar CRUD на реальном MySQL\n");
