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
notificationWorkerAssert(is_string($source), 'CLI worker уведомлений должен существовать');

foreach ([
    "CliRuntime::loadEnvironment()",
    'CliRuntime::registerAutoloader($root)',
    'DatabaseNotificationDeliveryQueue',
    'DatabaseNotificationEmailRecipientResolver',
    'EnvironmentNotificationSecretResolver',
    'SocketSmtpTransport',
    'SmtpNotificationChannel',
    'NotificationDeliveryWorker',
    "'notification_worker_failed'",
    "getenv('NOTIFICATION_WORKER_BATCH_SIZE')",
] as $fragment) {
    notificationWorkerAssert(str_contains($source, $fragment), 'CLI worker обязан содержать: ' . $fragment);
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
