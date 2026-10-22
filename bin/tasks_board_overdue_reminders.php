<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/core/Config.php';
require_once $root . '/modules/tasks/services/TaskBoardOverdueReminderPublisher.php';
require_once $root . '/app/services/notifications/NotificationEvent.php';
require_once $root . '/app/services/notifications/NotificationService.php';

use App\Services\Notifications\NotificationService;
use Core\CliRuntime;
use Core\DatabaseManager;
use Modules\Tasks\TaskBoardOverdueReminderPublisher;

$options = getopt('', ['page-size:', 'json', 'help']);
if (isset($options['help'])) {
    echo "Использование: php bin/tasks_board_overdue_reminders.php [--page-size=N] [--json]\n";
    exit(0);
}
$json = isset($options['json']);
try {
    $result = (new TaskBoardOverdueReminderPublisher(
        DatabaseManager::getInstance(), new NotificationService()
    ))->run(null, isset($options['page-size']) ? (int) $options['page-size'] : 100);
    if ($json) {
        CliRuntime::writeJson(['status' => 'ok', ...$result], true);
    } else {
        echo 'Пользователей: ' . $result['users'] . '; событий: ' . $result['published'] . PHP_EOL;
    }
    DatabaseManager::resetInstance();
} catch (Throwable $error) {
    DatabaseManager::resetInstance();
    CliRuntime::fail($error, $json, 'tasks_board_reminders_failed');
}
