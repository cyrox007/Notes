<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function calendarCrudAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] Calendar CRUD: {$message}\n");
        exit(1);
    }
}

require_once $root . '/modules/calendar/services/CalendarRecurrence.php';

$recurrence = new \Modules\Calendar\Services\CalendarRecurrence();
$timezone = new DateTimeZone('Europe/Stockholm');
$rule = $recurrence->encode([
    'frequency' => 'weekly',
    'interval' => 1,
    'weekdays' => ['SA'],
    'until' => '2026-11-07',
], $timezone);
calendarCrudAssert($rule === 'FREQ=WEEKLY;INTERVAL=1;BYDAY=SA;UNTIL=20261107T225959Z', 'RRULE должен сохранять локальную дату окончания через UTC');
$occurrences = $recurrence->occurrences(
    new DateTimeImmutable('2026-10-24T07:00:00Z'),
    'Europe/Stockholm',
    $rule,
    new DateTimeImmutable('2026-10-23T00:00:00Z'),
    new DateTimeImmutable('2026-11-08T00:00:00Z')
);
calendarCrudAssert(count($occurrences) === 3, 'Еженедельная серия должна развернуться в три экземпляра');
calendarCrudAssert($occurrences[0]->format('Y-m-d H:i:s') === '2026-10-24 07:00:00', 'Первый экземпляр должен быть в CEST');
calendarCrudAssert($occurrences[1]->format('Y-m-d H:i:s') === '2026-10-31 08:00:00', 'После DST локальные 09:00 должны стать 08:00 UTC');
calendarCrudAssert($occurrences[2]->format('Y-m-d H:i:s') === '2026-11-07 08:00:00', 'Локальное время серии не должно сдвигаться после DST');

$provider = (string) file_get_contents($root . '/modules/calendar/CalendarRuntimeProvider.php');
calendarCrudAssert(str_contains($provider, "->group('/calendar')"), 'UI-маршрут Calendar отсутствует');
calendarCrudAssert(str_contains($provider, "->group('/api/calendar/events')"), 'API-маршруты Calendar отсутствуют');
foreach (["'GET'", "'POST'", "'PUT'", "'DELETE'"] as $method) {
    calendarCrudAssert(str_contains($provider, $method), 'CRUD API не содержит метод ' . $method);
}
calendarCrudAssert(str_contains($provider, 'CSRFMiddleware::class'), 'Изменяющие API-маршруты должны иметь CSRF');
calendarCrudAssert(str_contains($provider, 'RequireCalendarUse::class'), 'UI Calendar должен проверять calendar.use');

$service = (string) file_get_contents($root . '/modules/calendar/services/CalendarEventService.php');
calendarCrudAssert(str_contains($service, 'owner_user_id = :user_id'), 'CRUD должен всегда ограничивать события текущим владельцем');
calendarCrudAssert(str_contains($service, "state = 'pending'"), 'Изменение события должно пересобирать только ожидающие напоминания');
calendarCrudAssert(!str_contains($service, 'notification_channel_configs'), 'Calendar не должен управлять конфигурацией внешних каналов');
calendarCrudAssert(!str_contains($service, 'secret_ref'), 'Calendar не должен читать секреты уведомлений');

$planner = (string) file_get_contents($root . '/modules/calendar/services/CalendarReminderPlanner.php');
calendarCrudAssert(str_contains($planner, 'HORIZON_DAYS'), 'Повторяющиеся события должны иметь rolling-планирование');
calendarCrudAssert(str_contains($planner, 'calendar_event_reminder_offsets'), 'Планировщик должен читать сохранённые смещения события');

$migration = (string) file_get_contents($root . '/database/migrations/20261011_calendar_crud.sql');
calendarCrudAssert(str_contains($migration, "'calendar.use'"), 'Миграция должна добавить RBAC-разрешение Calendar');
calendarCrudAssert(str_contains($migration, 'calendar_event_reminder_offsets'), 'Миграция должна создать таблицу сохранённых напоминаний');

$manifest = json_decode((string) file_get_contents($root . '/modules/calendar/module.json'), true, 32, JSON_THROW_ON_ERROR);
calendarCrudAssert(in_array('calendar_event_reminder_offsets', $manifest['database']['tables'] ?? [], true), 'module.json не объявляет таблицу offsets');
calendarCrudAssert(in_array('database/migrations/20261011_calendar_crud.sql', $manifest['database']['migrations'] ?? [], true), 'module.json не объявляет CRUD-миграцию');

$view = (string) file_get_contents($root . '/modules/calendar/views/index.php');
calendarCrudAssert(str_contains($view, "moduleAsset('calendar', 'calendar.css')"), 'UI должен использовать module asset CSS');
calendarCrudAssert(str_contains($view, "moduleAsset('calendar', 'calendar.js')"), 'UI должен использовать module asset JS');
foreach (['month', 'week', 'day'] as $mode) {
    calendarCrudAssert(str_contains($view, 'data-calendar-view="' . $mode . '"'), 'UI не содержит режим ' . $mode);
}

$script = (string) file_get_contents($root . '/modules/calendar/assets/calendar.js');
calendarCrudAssert(!str_contains($script, '.innerHTML'), 'UI Calendar не должен вставлять данные через innerHTML');
calendarCrudAssert(str_contains($script, "method = uid ? 'PUT' : 'POST'"), 'UI должен различать создание и обновление');
calendarCrudAssert(str_contains($script, "method: 'DELETE'"), 'UI должен поддерживать удаление');

fwrite(STDOUT, "[OK] Calendar CRUD, повторения и UI\n");
