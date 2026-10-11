<?php

declare(strict_types=1);

use Core\CliRuntime;
use Modules\Calendar\CalendarCapability;

$root = dirname(__DIR__);
require_once $root . '/core/CliRuntime.php';
CliRuntime::loadEnvironment($root);
CliRuntime::registerAutoloader($root);

$options = getopt('', ['limit:', 'catch-up-seconds:', 'json', 'help']);
if (isset($options['help'])) {
    echo "Использование: php bin/calendar_reminders.php [--limit=N] [--catch-up-seconds=N] [--json]\n";
    echo "  --limit=N             Максимум напоминаний за один проход, от 1 до 100.\n";
    echo "  --catch-up-seconds=N  Окно восстановления пропущенных напоминаний, от 60 до 2592000 секунд.\n";
    echo "  --json                Вывести результат в JSON.\n";
    exit(0);
}

$limit = isset($options['limit']) ? filter_var($options['limit'], FILTER_VALIDATE_INT) : 20;
$catchUp = isset($options['catch-up-seconds'])
    ? filter_var($options['catch-up-seconds'], FILTER_VALIDATE_INT)
    : 604800;
$json = isset($options['json']);

if (!is_int($limit) || $limit < 1 || $limit > 100) {
    fwrite(STDERR, "Некорректный --limit: допустимо значение от 1 до 100.\n");
    exit(2);
}
if (!is_int($catchUp) || $catchUp < 60 || $catchUp > 2592000) {
    fwrite(STDERR, "Некорректный --catch-up-seconds: допустимо значение от 60 до 2592000 секунд.\n");
    exit(2);
}

try {
    $provider = require $root . '/modules/calendar/runtime.php';
    $capabilities = $provider->capabilities();
    $calendar = $capabilities['workspace.calendar'] ?? null;
    if (!$calendar instanceof CalendarCapability) {
        throw new RuntimeException('Модуль Calendar не предоставил capability workspace.calendar');
    }

    $planning = $calendar->maintainUpcomingReminders(min($limit, 50));
    $result = $calendar->publishDueReminders($limit, $catchUp);
    if ($json) {
        CliRuntime::writeJson(['status' => 'ok', 'planning' => $planning] + $result);
        exit(0);
    }

    if ($result['locked']) {
        echo "Проход пропущен: обработчик напоминаний уже работает.\n";
        exit(0);
    }

    printf(
        "Calendar: проверено серий %d, подготовлено напоминаний %d; выбрано %d, опубликовано %d, ошибок %d, просрочено %d.\n",
        $planning['selected'],
        $planning['scheduled'],
        $result['selected'],
        $result['published'],
        $result['failed'],
        $result['expired']
    );
} catch (Throwable $e) {
    CliRuntime::fail($e, $json, 'calendar_reminder_run_failed');
}
