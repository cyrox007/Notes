<?php

declare(strict_types=1);

use Modules\Calendar\CalendarRuntimeProvider;

$moduleRoot = __DIR__;
$files = [
    $moduleRoot . '/services/CalendarReminderScheduler.php',
    $moduleRoot . '/services/CalendarReminderPublisher.php',
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
