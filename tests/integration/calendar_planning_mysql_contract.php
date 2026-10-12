<?php

declare(strict_types=1);

use Core\DatabaseManager;
use Core\ModuleCapabilityRegistry;
use Modules\Calendar\Services\CalendarAgendaService;
use Modules\Calendar\Services\CalendarEventService;
use Modules\Calendar\Services\CalendarLinkService;
use Modules\Calendar\Services\CalendarRecurrence;
use Modules\Calendar\Services\CalendarReminderPlanner;
use Modules\Calendar\Services\CalendarReminderScheduler;
use Modules\Calendar\Services\CalendarTimeBlockService;

$root = dirname(__DIR__, 2);
$dbHost = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$dbPort = (int) (getenv('TEST_DB_PORT') ?: 3306);
$dbName = getenv('TEST_DB_NAME') ?: 'workspace_test';
$dbUser = getenv('TEST_DB_USER') ?: 'workspace';
$dbPass = getenv('TEST_DB_PASS') ?: 'workspace';
$dbRootPass = getenv('TEST_DB_ROOT_PASS') ?: 'root';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($dbHost, 'root', $dbRootPass, $dbName, $dbPort);
$mysqli->set_charset('utf8mb4');

function calendarPlanningMysqlAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function calendarPlanningApplySql(mysqli $db, string $path): void
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

function calendarPlanningInsertUser(mysqli $db, string $uid, string $username): int
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

calendarPlanningApplySql($mysqli, $root . '/database/core_identity_schema.sql');
calendarPlanningApplySql($mysqli, $root . '/database/access_control_schema.sql');
calendarPlanningApplySql($mysqli, $root . '/database/migrations/20261010_notifications_foundation.sql');
calendarPlanningApplySql($mysqli, $root . '/database/migrations/20261011_calendar_reminders.sql');
calendarPlanningApplySql($mysqli, $root . '/database/migrations/20261011_calendar_crud.sql');
calendarPlanningApplySql($mysqli, $root . '/database/migrations/20261012_calendar_planning_links.sql');

$mysqli->query('SET FOREIGN_KEY_CHECKS=0');
foreach ([
    'calendar_item_links', 'calendar_time_blocks', 'calendar_reminders',
    'calendar_event_reminder_offsets', 'calendar_events', 'notification_delivery_attempts',
    'notification_delivery_jobs', 'notifications', 'notification_events',
    'notification_subscriptions', 'notification_channel_configs', 'user_roles'
] as $table) {
    $mysqli->query('DELETE FROM `' . $table . '`');
}
$mysqli->query("DELETE FROM users WHERE username LIKE 'calendar-planning-test-%'");
$mysqli->query('SET FOREIGN_KEY_CHECKS=1');

$userA = calendarPlanningInsertUser($mysqli, '52000000-0000-4000-8000-000000000001', 'calendar-planning-test-a');
$userB = calendarPlanningInsertUser($mysqli, '52000000-0000-4000-8000-000000000002', 'calendar-planning-test-b');

putenv('DBDRIVER=mysql');
putenv('DBHOST=' . $dbHost);
putenv('DBPORT=' . $dbPort);
putenv('DBNAME=' . $dbName);
putenv('DBUSER=' . $dbUser);
putenv('DBPASS=' . $dbPass);

require_once $root . '/core/Config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/core/Uuid.php';
require_once $root . '/core/ModuleCapabilityRegistry.php';
require_once $root . '/core/WorkspaceCalendarLinkTargetProvider.php';
require_once $root . '/modules/calendar/services/CalendarRecurrence.php';
require_once $root . '/modules/calendar/services/CalendarReminderScheduler.php';
require_once $root . '/modules/calendar/services/CalendarReminderPlanner.php';
require_once $root . '/modules/calendar/services/CalendarEventService.php';
require_once $root . '/modules/calendar/services/CalendarTimeBlockService.php';
require_once $root . '/modules/calendar/services/CalendarAgendaService.php';
require_once $root . '/modules/calendar/services/CalendarLinkService.php';

DatabaseManager::resetInstance();
$db = DatabaseManager::getInstance();
$recurrence = new CalendarRecurrence();
$events = new CalendarEventService(
    $db,
    $recurrence,
    new CalendarReminderPlanner($db, new CalendarReminderScheduler($db), $recurrence)
);
$blocks = new CalendarTimeBlockService($db);
$agenda = new CalendarAgendaService($events, $blocks);
$links = new CalendarLinkService($db, new ModuleCapabilityRegistry());

$event = $events->createForUser($userA, [
    'title' => 'Совещание',
    'description' => '',
    'location' => 'Переговорная',
    'all_day' => false,
    'timezone' => 'Europe/Stockholm',
    'starts_at' => '2026-10-12T10:00',
    'ends_at' => '2026-10-12T11:00',
    'recurrence' => null,
    'reminder_offsets' => [],
]);
$block = $blocks->createForUser($userA, [
    'title' => 'Сосредоточенная работа',
    'description' => 'Без встреч и мессенджера',
    'location' => 'Кабинет',
    'timezone' => 'Europe/Stockholm',
    'starts_at' => '2026-10-12T13:00',
    'ends_at' => '2026-10-12T15:00',
]);
calendarPlanningMysqlAssert(($block['kind'] ?? null) === 'time_block', 'Временной блок должен иметь собственный тип');
calendarPlanningMysqlAssert($block['local_start'] === '2026-10-12T13:00:00', 'Временной блок должен сохранять локальное время');

$items = $agenda->listForUser(
    $userA,
    new DateTimeImmutable('2026-10-12T00:00:00+02:00'),
    new DateTimeImmutable('2026-10-12T23:59:59+02:00')
)['items'];
calendarPlanningMysqlAssert(count($items) === 2, 'Ежедневник должен объединять событие и временной блок');
calendarPlanningMysqlAssert($items[0]['kind'] === 'event', 'Первым элементом должно быть событие');
calendarPlanningMysqlAssert($items[1]['kind'] === 'time_block', 'Вторым элементом должен быть временной блок');

$foreignItems = $agenda->listForUser(
    $userB,
    new DateTimeImmutable('2026-10-12T00:00:00+02:00'),
    new DateTimeImmutable('2026-10-12T23:59:59+02:00')
)['items'];
calendarPlanningMysqlAssert($foreignItems === [], 'Ежедневник не должен показывать чужие объекты');

$storedEventLinks = $links->replaceLinks($userA, 'event', (string) $event['uid'], [
    ['type' => 'task', 'uid' => 'opaque-task-uid'],
    ['type' => 'note', 'uid' => 'opaque-note-uid'],
]);
calendarPlanningMysqlAssert(count($storedEventLinks) === 2, 'Calendar должен хранить несколько типизированных связей');
calendarPlanningMysqlAssert($storedEventLinks[0]['available'] === false, 'Без активной capability чужие метаданные не должны раскрываться');

$storedBlockLinks = $links->replaceLinks($userA, 'time_block', (string) $block['uid'], [
    ['type' => 'file', 'uid' => 'opaque-file-uid'],
]);
calendarPlanningMysqlAssert(count($storedBlockLinks) === 1, 'Временной блок должен поддерживать связи');
calendarPlanningMysqlAssert(
    (int) $db->fetchValue('SELECT COUNT(*) FROM calendar_item_links WHERE owner_user_id = :user_id', [':user_id' => $userA]) === 3,
    'Связи должны храниться только в таблице Calendar'
);

$foreignRejected = false;
try {
    $links->linksForItem($userB, 'event', (string) $event['uid']);
} catch (InvalidArgumentException) {
    $foreignRejected = true;
}
calendarPlanningMysqlAssert($foreignRejected, 'Нельзя читать связи чужого объекта Calendar');

$updated = $blocks->updateForUser($userA, (string) $block['uid'], [
    'title' => 'Глубокая работа',
    'description' => '',
    'location' => '',
    'timezone' => 'Europe/Stockholm',
    'starts_at' => '2026-10-12T14:00',
    'ends_at' => '2026-10-12T16:00',
]);
calendarPlanningMysqlAssert($updated['title'] === 'Глубокая работа', 'Временной блок должен обновляться');
$blocks->cancelForUser($userA, (string) $block['uid']);
calendarPlanningMysqlAssert(
    $blocks->listForUser($userA, new DateTimeImmutable('2026-10-12T00:00:00Z'), new DateTimeImmutable('2026-10-13T00:00:00Z')) === [],
    'Удалённый временной блок не должен возвращаться в ежедневник'
);

fwrite(STDOUT, "[OK] Ежедневник, временные блоки и связи Calendar на MySQL\n");
