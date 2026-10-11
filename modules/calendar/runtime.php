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
    $moduleRoot . '/middlewares.php',
    $moduleRoot . '/controllers/CalendarController.php',
    $moduleRoot . '/controllers/CalendarApiController.php',
    $moduleRoot . '/CalendarCapability.php',
    $moduleRoot . '/CalendarRuntimeProvider.php',
];
foreach ($files as $file) {
    if (!is_file($file)) {
        throw new RuntimeException('Не найден файл runtime модуля Calendar: ' . basename($file));
    }
    require_once $file;
}

return new CalendarRuntimeProvider();
