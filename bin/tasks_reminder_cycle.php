<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/core/Config.php';
require_once $root . '/modules/tasks/services/TaskPersonalQueue.php';
require_once $root . '/modules/tasks/services/TaskAssigneeQueueService.php';
require_once $root . '/modules/tasks/services/TaskMorningDigestPublisher.php';
require_once $root . '/modules/tasks/services/TaskOverdueReminderPublisher.php';
require_once $root . '/modules/tasks/services/TaskBoardOverdueReminderPublisher.php';
require_once $root . '/modules/tasks/services/TaskReminderCycle.php';

use App\Services\Notifications\NotificationActionTokenStore;
use App\Services\Notifications\NotificationDeliveryRuntime;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\SystemNotificationRuntime;
use Core\CliRuntime;
use Core\DatabaseManager;
use Modules\Tasks\TaskMorningDigestPublisher;
use Modules\Tasks\TaskOverdueReminderPublisher;
use Modules\Tasks\TaskBoardOverdueReminderPublisher;
use Modules\Tasks\TaskReminderCycle;

$options = getopt('', ['page-size:', 'delivery-limit:', 'diagnose', 'json', 'help']);
if (isset($options['help'])) {
    echo "Использование: php bin/tasks_reminder_cycle.php [--page-size=100] [--delivery-limit=20] [--json]\n";
    echo "Диагностика без отправки: php bin/tasks_reminder_cycle.php --diagnose --json\n";
    echo "Один проход трёх видов напоминаний и внешней очереди. Запускать каждые 15 минут.\n";
    exit(0);
}

$json = isset($options['json']);
try {
    $db = DatabaseManager::getInstance();
    $cycle = new TaskReminderCycle($db);
    if (isset($options['diagnose'])) {
        $result = $cycle->diagnose();
    } else {
        $pageSize = isset($options['page-size']) ? (int) $options['page-size'] : 100;
        $deliveryLimit = isset($options['delivery-limit']) ? (int) $options['delivery-limit'] : 20;
        if ($pageSize < 1 || $pageSize > 500 || $deliveryLimit < 1 || $deliveryLimit > 100) {
            throw new InvalidArgumentException('Размер страницы: 1–500; размер пакета доставки: 1–100');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $notices = new NotificationService();
        $workerId = 'tasks-reminders-' . substr(hash('sha256', (string) (gethostname() ?: 'host')), 0, 12)
            . '-' . max(1, (int) getmypid());
        $result = $cycle->run(
            static fn(): array => (new TaskMorningDigestPublisher($db, $notices))->run($now, $pageSize),
            static fn(): array => (new TaskOverdueReminderPublisher($db, $notices))->run($now, $pageSize),
            static fn(): array => (new TaskBoardOverdueReminderPublisher($db, $notices))->run($now, $pageSize),
            static function () use ($db, $root, $deliveryLimit, $workerId): array {
                $system = SystemNotificationRuntime::catchUp($root, $deliveryLimit);
                $removed = (new NotificationActionTokenStore($db))->purgeExpired(100);
                return [
                    'system_events' => $system,
                    'expired_tokens' => $removed,
                    ...(new NotificationDeliveryRuntime($db))->run($workerId, $deliveryLimit),
                ];
            }
        );
    }

    if ($json) {
        CliRuntime::writeJson($result, true);
    } else {
        echo 'Фоновый цикл Tasks: ' . ($result['status'] ?? 'unknown') . PHP_EOL;
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
    $exitCode = ($result['status'] ?? '') === 'failed' ? 3 : 0;
    DatabaseManager::resetInstance();
    exit($exitCode);
} catch (Throwable $error) {
    DatabaseManager::resetInstance();
    error_log('Ошибка фонового цикла Tasks: ' . $error::class);
    if ($json) {
        CliRuntime::writeJson(['status' => 'failed', 'error' => 'tasks_reminder_cycle_failed']);
    } else {
        fwrite(STDERR, "Не удалось выполнить фоновый цикл Tasks. Проверьте журнал сервера.\n");
    }
    exit(2);
}
