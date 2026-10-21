<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/core/Config.php';

use App\Services\Notifications\NotificationDeliveryRuntime;
use App\Services\Notifications\NotificationActionTokenStore;
use Core\CliRuntime;
use Core\DatabaseManager;

$options = getopt('', [
    'limit:',
    'worker-id:',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Использование: php bin/notifications_worker.php [--limit=N] [--worker-id=ID] [--json]\n";
    echo "Выполняет один ограниченный проход очереди внешних уведомлений и завершает работу.\n";
    echo "Команду можно запускать по cron или через планировщик задач.\n";
    echo "Пароль SMTP хранится только в переменной окружения, указанной как secret_ref=env:ИМЯ_ПЕРЕМЕННОЙ.\n";
    exit(0);
}

$json = isset($options['json']);
$limit = isset($options['limit'])
    ? (int) $options['limit']
    : (int) (getenv('NOTIFICATION_WORKER_BATCH_SIZE') ?: 20);
$workerId = isset($options['worker-id'])
    ? trim((string) $options['worker-id'])
    : notificationWorkerId();

try {
    if ($limit < 1 || $limit > 100) {
        throw new InvalidArgumentException('Размер пакета уведомлений должен быть в диапазоне 1..100');
    }

    \App\Services\Notifications\SystemNotificationRuntime::catchUp($root, $limit);
    $db = DatabaseManager::getInstance();
    // Один ограниченный пакет очистки на каждый проход фонового обработчика.
    $expiredTokensRemoved = (new NotificationActionTokenStore($db))->purgeExpired(100);
    $runtime = new NotificationDeliveryRuntime($db);
    $stats = $runtime->run($workerId, $limit);

    $result = [
        'status' => 'ok',
        'expired_action_tokens_removed' => $expiredTokensRemoved,
        'worker_id' => $workerId,
        'claimed' => $stats['claimed'],
        'sent' => $stats['sent'],
        'retry' => $stats['retry'],
        'failed' => $stats['failed'],
    ];

    if ($json) {
        CliRuntime::writeJson($result, true);
    } else {
        echo "Обработка очереди уведомлений завершена\n";
        echo 'Захвачено: ' . $stats['claimed'] . PHP_EOL;
        echo 'Отправлено: ' . $stats['sent'] . PHP_EOL;
        echo 'Ожидают повтора: ' . $stats['retry'] . PHP_EOL;
        echo 'Завершены ошибкой: ' . $stats['failed'] . PHP_EOL;
        echo 'Удалено просроченных подтверждений: ' . $expiredTokensRemoved . PHP_EOL;
    }

    DatabaseManager::resetInstance();
    exit($stats['failed'] > 0 ? 3 : 0);
} catch (Throwable $e) {
    DatabaseManager::resetInstance();
    CliRuntime::fail($e, $json, 'notification_worker_failed');
}

function notificationWorkerId(): string
{
    $host = (string) (gethostname() ?: 'host');
    $fingerprint = substr(hash('sha256', $host), 0, 12);
    $pid = max(1, (int) getmypid());
    return 'notifications-' . $fingerprint . '-' . $pid;
}
