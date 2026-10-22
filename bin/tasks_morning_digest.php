<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/core/Config.php';
require_once $root . '/modules/tasks/services/TaskAssigneeQueueService.php';
require_once $root . '/modules/tasks/services/TaskPersonalQueue.php';
require_once $root . '/modules/tasks/services/TaskMorningDigestPublisher.php';
require_once $root . '/app/services/notifications/NotificationEvent.php';
require_once $root . '/app/services/notifications/NotificationService.php';

use App\Services\Notifications\NotificationService;
use Core\CliRuntime;
use Core\DatabaseManager;
use Modules\Tasks\TaskMorningDigestPublisher;

$options = getopt('', ['limit:', 'json', 'help']);
if (isset($options['help'])) {
    echo "Использование: php bin/tasks_morning_digest.php [--limit=100] [--json]\n";
    echo "Запускать планировщиком каждые 10–15 минут. Уведомления публикуются в 08:00–08:59 местного времени.\n";
    exit(0);
}

$json = isset($options['json']);
try {
    $db = DatabaseManager::getInstance();
    $publisher = new TaskMorningDigestPublisher($db, new NotificationService());
    $result = $publisher->run(null, isset($options['limit']) ? (int) $options['limit'] : 100);
    if ($json) {
        CliRuntime::writeJson(['status' => 'ok', ...$result], true);
    } else {
        echo 'Проверено: ' . $result['checked'] . '; опубликовано: '
            . $result['published'] . '; пропущено: ' . $result['skipped'] . PHP_EOL;
    }
    DatabaseManager::resetInstance();
} catch (Throwable $error) {
    DatabaseManager::resetInstance();
    CliRuntime::fail($error, $json, 'tasks_digest_failed');
}
