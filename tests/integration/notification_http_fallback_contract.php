<?php

declare(strict_types=1);

function notificationHttpFallbackAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);
$runtime = file_get_contents($root . '/app/services/notifications/NotificationDeliveryRuntime.php');
$entryPoint = file_get_contents($root . '/core/ApplicationEntryPoint.php');
$migration = file_get_contents($root . '/database/migrations/20261012_notifications_http_fallback.sql');
$manifest = file_get_contents($root . '/database/migrations/manifest.json');
$ownership = file_get_contents($root . '/core/DatabaseOwnership.php');
$worker = file_get_contents($root . '/bin/notifications_worker.php');

notificationHttpFallbackAssert(is_string($runtime), 'Runtime внешней доставки должен существовать');
notificationHttpFallbackAssert(is_string($entryPoint), 'HTTP entry point должен существовать');
notificationHttpFallbackAssert(is_string($migration), 'Миграция состояния HTTP fallback должна существовать');
notificationHttpFallbackAssert(is_string($manifest), 'Канонический manifest миграций должен существовать');
notificationHttpFallbackAssert(is_string($ownership), 'Контракт владения БД должен существовать');
notificationHttpFallbackAssert(is_string($worker), 'CLI worker должен существовать');

foreach ([
    'public const HTTP_LIMIT = 3',
    'public const HTTP_INTERVAL_SECONDS = 60',
    'workspace.notifications.delivery.http_fallback',
    'SELECT GET_LOCK(:lock_name, 0)',
    'SELECT RELEASE_LOCK(:lock_name)',
    'notification_runtime_state',
    'rememberHttpRun($now)',
    'new NotificationDeliveryWorker',
] as $fragment) {
    notificationHttpFallbackAssert(
        str_contains($runtime, $fragment),
        'Runtime HTTP fallback обязан содержать: ' . $fragment
    );
}

notificationHttpFallbackAssert(
    !str_contains($runtime, 'CREATE TABLE'),
    'Runtime не должен создавать таблицы во время пользовательского запроса'
);
notificationHttpFallbackAssert(
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS `notification_runtime_state`'),
    'Состояние ограничения частоты должно принадлежать отдельной миграции'
);

$migrationName = '20261012_notifications_http_fallback.sql';
notificationHttpFallbackAssert(
    str_contains($manifest, $migrationName),
    'Новая миграция должна быть зарегистрирована в каноническом manifest'
);
notificationHttpFallbackAssert(
    str_contains($ownership, $migrationName),
    'Новая миграция должна принадлежать Core в DatabaseOwnership'
);
$foundationPosition = strpos($manifest, '20261010_notifications_foundation.sql');
$fallbackPosition = strpos($manifest, $migrationName);
notificationHttpFallbackAssert(
    is_int($foundationPosition) && is_int($fallbackPosition) && $foundationPosition < $fallbackPosition,
    'HTTP fallback должен применяться после базовой миграции уведомлений'
);

foreach ([
    'scheduleNotificationHttpFallback()',
    'register_shutdown_function',
    'session_write_close()',
    "function_exists('fastcgi_finish_request')",
    'fastcgi_finish_request()',
    'isMessengerLongPollRequest()',
    "['HEAD', 'OPTIONS']",
    'module-assets',
    "'notifications.http_fallback_failed'",
    "['error_type' =>",
    '$e::class',
] as $fragment) {
    notificationHttpFallbackAssert(
        str_contains($entryPoint, $fragment),
        'HTTP entry point обязан содержать защиту: ' . $fragment
    );
}

notificationHttpFallbackAssert(
    !str_contains($entryPoint, 'getMessage()'),
    'HTTP fallback не должен писать текст исключения с потенциальными секретами'
);
notificationHttpFallbackAssert(
    str_contains($worker, 'new NotificationDeliveryRuntime'),
    'CLI и HTTP должны использовать общий runtime доставки'
);

fwrite(STDOUT, "[OK] HTTP fallback внешних уведомлений 1.1.0\n");
