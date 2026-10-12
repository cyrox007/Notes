<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function calendarPlanningAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function calendarPlanningRead(string $root, string $path): string
{
    $bytes = file_get_contents($root . '/' . $path);
    if (!is_string($bytes)) {
        throw new RuntimeException('Не удалось прочитать ' . $path);
    }
    return $bytes;
}

$calendarManifest = json_decode(calendarPlanningRead($root, 'modules/calendar/module.json'), true, 32, JSON_THROW_ON_ERROR);
calendarPlanningAssert(($calendarManifest['dependencies'] ?? null) === [], 'Calendar не должен получать жёсткие зависимости от соседних модулей');
calendarPlanningAssert(in_array('calendar_time_blocks', $calendarManifest['database']['tables'] ?? [], true), 'Calendar должен владеть таблицей временных блоков');
calendarPlanningAssert(in_array('calendar_item_links', $calendarManifest['database']['tables'] ?? [], true), 'Calendar должен владеть таблицей связей');
calendarPlanningAssert(
    in_array('database/migrations/20261012_calendar_planning_links.sql', $calendarManifest['database']['migrations'] ?? [], true),
    'Миграция планирования должна принадлежать Calendar'
);

foreach ([
    'tasks' => 'workspace.tasks.calendar-links',
    'notes' => 'workspace.notes.calendar-links',
    'files' => 'workspace.files.calendar-links',
] as $module => $capability) {
    $manifest = json_decode(calendarPlanningRead($root, 'modules/' . $module . '/module.json'), true, 32, JSON_THROW_ON_ERROR);
    calendarPlanningAssert(in_array($capability, $manifest['capabilities'] ?? [], true), $module . ' должен объявлять публичную capability для Calendar');
}

$linkService = calendarPlanningRead($root, 'modules/calendar/services/CalendarLinkService.php');
foreach (['FROM tasks', 'FROM notes', 'FROM user_files', 'task_board_items'] as $forbidden) {
    calendarPlanningAssert(!str_contains($linkService, $forbidden), 'Calendar не должен напрямую читать таблицы соседних модулей: ' . $forbidden);
}
calendarPlanningAssert(str_contains($linkService, 'WorkspaceCalendarLinkTargetProvider::class'), 'Calendar должен разрешать связи через общий контракт Core');
calendarPlanningAssert(str_contains($linkService, 'workspace.tasks.calendar-links'), 'Calendar должен использовать capability Tasks');
calendarPlanningAssert(str_contains($linkService, 'workspace.notes.calendar-links'), 'Calendar должен использовать capability Notes');
calendarPlanningAssert(str_contains($linkService, 'workspace.files.calendar-links'), 'Calendar должен использовать capability Files');

$provider = calendarPlanningRead($root, 'modules/calendar/CalendarRuntimeProvider.php');
foreach (['/agenda', '/link-targets', '/links', '/time-blocks'] as $route) {
    calendarPlanningAssert(str_contains($provider, $route), 'Calendar runtime должен регистрировать маршрут планирования ' . $route);
}

$view = calendarPlanningRead($root, 'modules/calendar/views/index.php');
calendarPlanningAssert(str_contains($view, 'data-calendar-planning-root'), 'Интерфейс должен содержать ежедневник');
calendarPlanningAssert(str_contains($view, 'data-calendar-block-editor'), 'Интерфейс должен содержать редактор временного блока');
calendarPlanningAssert(str_contains($view, 'data-calendar-links-editor'), 'Интерфейс должен содержать управление связями');
calendarPlanningAssert(str_contains($view, 'calendar-planning.js'), 'Интерфейс должен подключать отдельный runtime планирования');

$migration = calendarPlanningRead($root, 'database/migrations/20261012_calendar_planning_links.sql');
calendarPlanningAssert(str_contains($migration, 'calendar_time_blocks'), 'Миграция должна создавать временные блоки');
calendarPlanningAssert(str_contains($migration, 'calendar_item_links'), 'Миграция должна создавать связи');
calendarPlanningAssert(!str_contains($migration, 'FOREIGN KEY (`target_uid`)'), 'Связи не должны создавать межмодульный внешний ключ');

fwrite(STDOUT, "[OK] Контракт ежедневника и межмодульных связей Calendar\n");
