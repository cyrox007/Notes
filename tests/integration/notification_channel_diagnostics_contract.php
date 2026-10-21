<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/bin/notifications_diagnose.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Команда диагностики каналов должна существовать');
}

foreach ([
    'Core\DatabaseManager',
    'notification_channel_configs',
    'notification_subscriptions',
    'notification_delivery_jobs',
    'notification_delivery_attempts',
    "'channels' => \$channels",
    'actual_delivery_verified',
    "'email', 'browser', 'telegram', 'max', 'webhook', 'sms'",
] as $fragment) {
    if (!str_contains($source, $fragment)) {
        throw new RuntimeException('Диагностика не включает контракт: ' . $fragment);
    }
}

foreach (['secret_ref', 'config_json', 'recipient_ref', 'endpoint', 'password'] as $secretField) {
    if (str_contains($source, 'SELECT ' . $secretField)) {
        throw new RuntimeException('Диагностика не должна извлекать чувствительное поле: ' . $secretField);
    }
}

echo "Контракт агрегированной диагностики всех каналов выполнен\n";
