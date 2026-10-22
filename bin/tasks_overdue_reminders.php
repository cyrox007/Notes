<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/core/Config.php';
require_once $root . '/modules/tasks/services/TaskOverdueReminderPublisher.php';
require_once $root . '/app/services/notifications/NotificationEvent.php';
require_once $root . '/app/services/notifications/NotificationService.php';

use Core\CliRuntime;
use Core\DatabaseManager;
use App\Services\Notifications\NotificationService;
use Modules\Tasks\TaskOverdueReminderPublisher;

$options = getopt('', ['page-size:', 'json', 'help']);
if (isset($options['help'])) {
    echo "Использование: php bin/tasks_overdue_reminders.php [--page-size=N] [--json]\n";
    echo "Запускать каждые 15 минут; повторные напоминания публикуются в 09:00–09:59 местного времени.\n";
    exit(0);
}
$json = isset($options['json']);
try {
    $result = (new TaskOverdueReminderPublisher(
        DatabaseManager::getInstance(), new NotificationService()
    ))->run(null, isset($options['page-size']) ? (int) $options['page-size'] : 100);
    if ($json) {
        CliRuntime::writeJson(['status' => 'ok', ...$result], true);
    } else {
        echo 'Обработано пользователей: ' . $result['users']
            . '; опубликовано событий: ' . $result['published'] . PHP_EOL;
    }
    DatabaseManager::resetInstance();
} catch (Throwable $error) {
    DatabaseManager::resetInstance();
    CliRuntime::fail($error, $json, 'tasks_overdue_reminders_failed');
}
