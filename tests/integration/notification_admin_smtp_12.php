<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/services/notifications/NotificationChannelAdministration.php';

use App\Services\Notifications\NotificationChannelAdministration;

function notificationAdministrationCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'Администрирование SMTP: ' . $message . PHP_EOL);
        exit(1);
    }
}

$input = [
    'host' => 'smtp.example.test',
    'port' => '587',
    'encryption' => 'tls',
    'username' => 'sender@example.test',
    'from_email' => 'notifications@example.test',
    'from_name' => 'Notes',
];
$settings = NotificationChannelAdministration::validateSmtp($input);
notificationAdministrationCheck($settings['host'] === 'smtp.example.test'
    && $settings['port'] === 587 && $settings['encryption'] === 'tls',
    'корректная конфигурация SMTP должна приниматься');

foreach ([
    ['host' => "bad\r\nX-Header: bad"],
    ['port' => '99999'],
    ['encryption' => 'none'],
    ['from_email' => 'not-an-email'],
    ['from_name' => "Notes\r\nCc: spam"],
] as $changes) {
    $blocked = false;
    try {
        NotificationChannelAdministration::validateSmtp(array_replace($input, $changes));
    } catch (InvalidArgumentException) {
        $blocked = true;
    }
    notificationAdministrationCheck($blocked, 'небезопасная конфигурация не отклонена');
}

$routes = file_get_contents($root . '/modules/admin/AdminRuntimeProvider.php');
$controller = file_get_contents($root . '/modules/admin/controllers/NotificationSettingsController.php');
$service = file_get_contents($root . '/app/services/notifications/NotificationChannelAdministration.php');
$view = file_get_contents($root . '/modules/admin/views/notifications.php');

notificationAdministrationCheck(is_string($routes) && is_string($controller)
    && is_string($service) && is_string($view), 'не все файлы административного канала доступны');

foreach (['admin_notifications', 'admin_notifications_smtp', 'admin_notifications_test'] as $route) {
    notificationAdministrationCheck(str_contains($routes, $route), 'отсутствует маршрут ' . $route);
}
notificationAdministrationCheck(
    substr_count($routes, 'NotificationSettingsController::class') === 4
    && str_contains($routes, "'admin_notifications_external'")
    && str_contains($routes, 'RequireAdminSettingsManage::class, CSRFMiddleware::class'),
    'изменяющие маршруты требуют права и защитный токен'
);
foreach (['NotificationService(', 'DatabaseNotificationPreferences(', 'NotificationDeliveryRuntime(', 'testSmtp', 'last_result_code'] as $contract) {
    notificationAdministrationCheck(str_contains($service, $contract), 'отсутствует этап полной очереди: ' . $contract);
}
notificationAdministrationCheck(
    str_contains($controller, 'CryptMethods::verifyPassword')
    && !str_contains($controller, 'smtp_password'),
    'сохранение должно требовать пароль администратора, не принимать секрет SMTP'
);
notificationAdministrationCheck(
    str_contains($view, 'data-notification-ajax-form')
    && str_contains($view, 'state')
    && str_contains($view, 'last_result_code'),
    'админка должна показывать запрос и результат доставки'
);
notificationAdministrationCheck(
    str_contains($service, 'FROM notification_delivery_attempts a')
    && str_contains($service, 'ORDER BY a.id DESC LIMIT 40')
    && str_contains($service, "'attempts' => " . '$attempts')
    && !str_contains($service, 'a.error_summary'),
    'история попыток должна иметь строгий предел и не извлекать потенциально чувствительные тексты ошибок'
);
notificationAdministrationCheck(
    str_contains($view, 'Последние попытки доставки')
    && str_contains($view, "e(" . '$attempt[' . "'result_code']" )
    && str_contains($view, 'colspan="6"'),
    'админка должна безопасно показывать последние результаты всех каналов'
);

notificationAdministrationCheck(
    str_contains($service, "['email', 'browser', 'telegram', 'max', 'webhook', 'sms']")
    && str_contains($service, "new PushSubscriptionService(")
    && str_contains($service, "'activeDevices'") === false
    && str_contains($service, "['active'] ?? 0")
    && str_contains($service, "owner_user_id = :actor"),
    'Push-тест должен проверять личную подписку и запрещать чужие устройства'
);
notificationAdministrationCheck(
    str_contains($view, 'name="channel" value="browser"')
    && str_contains($view, 'Проверить Push на моих устройствах')
    && str_contains($view, 'data-notification-ajax-form'),
    'Push-тест должен быть доступен в AJAX-интерфейсе администратора'
);

echo "Проверены ограничения SMTP, защита маршрутов и полный путь диагностического события\n";
