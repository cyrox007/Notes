<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/core/Config.php';
require_once $root . '/app/services/notifications/NotificationLiveAcceptanceReport.php';

use App\Services\Notifications\NotificationLiveAcceptanceReport;
use Core\CliRuntime;
use Core\DatabaseManager;

$options = getopt('', ['job-id:', 'json', 'help']);
if (isset($options['help'])) {
    echo "Использование: php bin/notifications_acceptance_report.php --job-id=N [--json]\n";
    echo "Проверяет запись очереди и попытки без отправки сообщений.\n";
    exit(0);
}

$json = isset($options['json']);
$id = (string) ($options['job-id'] ?? '');
if ($id === '' || !ctype_digit($id) || (int) $id < 1) {
    fwrite(STDERR, "Укажите положительный --job-id.\n");
    exit(2);
}
try {
    $report = (new NotificationLiveAcceptanceReport(DatabaseManager::getInstance()))->forJob((int) $id);
    if ($json) {
        CliRuntime::writeJson($report, true);
    } else {
        echo 'Состояние: ' . ($report['queue_state'] ?? 'не найдено') . PHP_EOL;
        echo 'Принято внешним каналом: ' . (!empty($report['transport_accepted']) ? 'да' : 'нет') . PHP_EOL;
        echo "Фактическое получение адресатом: не проверено\n";
        echo 'Попыток: ' . ($report['attempts_total'] ?? 0) . PHP_EOL;
    }
    DatabaseManager::resetInstance();
    exit(($report['status'] ?? '') === 'ok' ? 0 : 3);
} catch (Throwable $error) {
    DatabaseManager::resetInstance();
    error_log('Отчёт приёмки уведомлений: ' . $error::class);
    if ($json) {
        CliRuntime::writeJson(['status' => 'error', 'code' => 'acceptance_report_unavailable']);
    } else {
        fwrite(STDERR, "Отчёт недоступен. Проверьте миграции и журнал сервера.\n");
    }
    exit(2);
}
