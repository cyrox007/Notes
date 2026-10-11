<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function notificationPreferencesAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$service = file_get_contents($root . '/app/services/notifications/DatabaseNotificationPreferences.php');
$controller = file_get_contents($root . '/app/controllers/NotificationController.php');
$routes = file_get_contents($root . '/core/routerConfig.php');

notificationPreferencesAssert(is_string($service), 'Не удалось прочитать сервис настроек уведомлений');
notificationPreferencesAssert(is_string($controller), 'Не удалось прочитать контроллер уведомлений');
notificationPreferencesAssert(is_string($routes), 'Не удалось прочитать маршруты уведомлений');

foreach ([
    "private const SUPPORTED_CHANNELS = [\n        'email' => true,",
    "FROM notification_subscriptions",
    "FROM notification_channel_configs",
    "AND (owner_user_id IS NULL OR owner_user_id = :user_id)",
    "in_app_enabled = 1",
    "ON DUPLICATE KEY UPDATE",
    "'external_enabled'",
    "'effective_channels'",
    "FOR UPDATE",
] as $fragment) {
    notificationPreferencesAssert(
        str_contains($service, $fragment),
        'Сервис настроек обязан сохранять контракт: ' . $fragment
    );
}

foreach (['config_json', 'secret_ref', 'password', 'smtp_password'] as $forbidden) {
    notificationPreferencesAssert(
        !str_contains($service, $forbidden),
        'Пользовательский сервис настроек не должен читать или возвращать секрет: ' . $forbidden
    );
}

notificationPreferencesAssert(
    !str_contains($service, 'INSERT INTO notification_channel_configs')
        && !str_contains($service, 'UPDATE notification_channel_configs'),
    'Пользовательский API не должен изменять серверную конфигурацию каналов'
);

foreach ([
    "->add('GET', '/preferences', [NotificationController::class, 'preferences'], [ApiLoginRequired::class]",
    "->add('PATCH', '/preferences', [NotificationController::class, 'updatePreferences'], [ApiLoginRequired::class, CSRFMiddleware::class]",
] as $fragment) {
    notificationPreferencesAssert(
        str_contains($routes, $fragment),
        'Маршруты настроек уведомлений обязаны сохранять защиту: ' . $fragment
    );
}

foreach ([
    'DatabaseNotificationPreferences',
    "'notifications.subscription.updated'",
    "'external_enabled'",
    "'channels'",
] as $fragment) {
    notificationPreferencesAssert(
        str_contains($controller, $fragment),
        'Контроллер настроек обязан сохранять контракт: ' . $fragment
    );
}

notificationPreferencesAssert(
    !str_contains($controller, 'secret_ref') && !str_contains($controller, 'config_json'),
    'Контроллер настроек не должен работать с секретами каналов'
);

fwrite(STDOUT, "[OK] архитектурный контракт пользовательских настроек уведомлений 1.1.0\n");
