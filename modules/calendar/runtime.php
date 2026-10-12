<?php

declare(strict_types=1);

use Modules\Calendar\CalendarRuntimeProvider;

$moduleRoot = __DIR__;
$files = [
    $moduleRoot . '/services/CalendarRecurrence.php',
    $moduleRoot . '/services/CalendarReminderScheduler.php',
    $moduleRoot . '/services/CalendarReminderPlanner.php',
    $moduleRoot . '/services/CalendarReminderPublisher.php',
    $moduleRoot . '/services/CalendarEventService.php',
    $moduleRoot . '/services/CalendarTimeBlockService.php',
    $moduleRoot . '/services/CalendarAgendaService.php',
    $moduleRoot . '/services/CalendarLinkService.php',
    $moduleRoot . '/middlewares.php',
    $moduleRoot . '/controllers/CalendarController.php',
    $moduleRoot . '/controllers/CalendarApiController.php',
    $moduleRoot . '/controllers/CalendarPlanningApiController.php',
    $moduleRoot . '/CalendarCapability.php',
    $moduleRoot . '/CalendarRuntimeProvider.php',
];
foreach ($files as $file) {
    if (!is_file($file) || is_link($file)) {
        throw new RuntimeException('Не найден или небезопасен файл runtime модуля Calendar: ' . basename($file));
    }
    require_once $file;
}

return new CalendarRuntimeProvider();
