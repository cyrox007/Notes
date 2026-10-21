<?php

declare(strict_types=1);

function notificationWorkerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);
$workerPath = $root . '/bin/notifications_worker.php';
$source = file_get_contents($workerPath);
$runtimeSource = file_get_contents($root . '/app/services/notifications/NotificationDeliveryRuntime.php');
notificationWorkerAssert(is_string($source), 'CLI worker уведомлений должен существовать');
notificationWorkerAssert(is_string($runtimeSource), 'Общий runtime доставки уведомлений должен существовать');

foreach ([
    "CliRuntime::loadEnvironment()",
    'CliRuntime::registerAutoloader($root)',
    'NotificationDeliveryRuntime',
    "'notification_worker_failed'",
    "getenv('NOTIFICATION_WORKER_BATCH_SIZE')",
] as $fragment) {
    notificationWorkerAssert(str_contains($source, $fragment), 'CLI worker обязан содержать: ' . $fragment);
}

foreach ([
    'DatabaseNotificationDeliveryQueue',
    'DatabaseNotificationEmailRecipientResolver',
    'EnvironmentNotificationSecretResolver',
    'SocketSmtpTransport',
    'SmtpNotificationChannel',
    'NotificationDeliveryWorker',
] as $fragment) {
    notificationWorkerAssert(
        str_contains($runtimeSource, $fragment),
        'Общий runtime доставки обязан собирать компонент: ' . $fragment
    );
}

notificationWorkerAssert(!str_contains($source, 'while ('), 'Cron-worker должен выполнять один bounded-проход, а не вечный цикл');
notificationWorkerAssert(!str_contains($source, 'sleep('), 'Cron-worker не должен удерживать процесс ожиданием');
notificationWorkerAssert(!str_contains($source, 'mail('), 'CLI worker не должен обходить SMTP-адаптер через mail()');

$command = [PHP_BINARY, $workerPath, '--help'];
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$pipes = [];
$process = proc_open($command, $descriptors, $pipes, $root);
notificationWorkerAssert(is_resource($process), 'Не удалось запустить --help CLI worker');
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

notificationWorkerAssert($exitCode === 0, '--help CLI worker должен завершаться с кодом 0: ' . $stderr);
notificationWorkerAssert(str_contains((string) $stdout, 'один ограниченный проход'), 'Справка должна объяснять bounded-поведение');
notificationWorkerAssert(str_contains((string) $stdout, 'cron'), 'Справка должна описывать запуск по cron');
notificationWorkerAssert(str_contains((string) $stdout, 'secret_ref=env:'), 'Справка должна объяснять безопасное хранение SMTP-пароля');

fwrite(STDOUT, "[OK] CLI-контракт worker уведомлений 1.1.0\n");


// Пробная SMTP-доставка допускается только после явного --send.
// Проверка справки не требует подключения к БД и не отправляет сообщения.
$probePath = $root . '/bin/notifications_smtp_probe.php';
$probeSource = file_get_contents($probePath);
notificationWorkerAssert(is_string($probeSource), 'Команда пробной SMTP-отправки должна существовать');
foreach ([
    "isset(\$options['send'])",
    'SmtpNotificationChannel',
    'DatabaseNotificationEmailRecipientResolver',
    'EnvironmentNotificationSecretResolver',
    'SocketSmtpTransport',
    'delivery_confirmed',
] as $fragment) {
    notificationWorkerAssert(
        str_contains($probeSource, $fragment),
        'Пробная SMTP-команда обязана содержать: ' . $fragment
    );
}

$probeProcess = proc_open([PHP_BINARY, $probePath, '--help'], $descriptors, $probePipes, $root);
notificationWorkerAssert(is_resource($probeProcess), 'Не удалось запустить справку SMTP-проверки');
fclose($probePipes[0]);
$probeHelp = stream_get_contents($probePipes[1]);
$probeError = stream_get_contents($probePipes[2]);
fclose($probePipes[1]);
fclose($probePipes[2]);
notificationWorkerAssert(proc_close($probeProcess) === 0, 'Справка SMTP-проверки завершилась ошибкой: ' . $probeError);
notificationWorkerAssert(str_contains((string) $probeHelp, '--send'),
    'Справка SMTP-проверки обязана предупреждать о явном разрешении отправки');
