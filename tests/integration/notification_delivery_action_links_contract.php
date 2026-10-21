<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationDeliveryActionLinks.php';

use App\Services\Notifications\NotificationDeliveryActionLinks;

function linkContract(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

linkContract(NotificationDeliveryActionLinks::httpsBase('https://notes.example.test') === 'https://notes.example.test',
    'Допускается безопасный HTTPS-адрес установки');
foreach (['http://notes.example.test', 'https://someone:secret@notes.example.test', 'https://notes.example.test/?x=1', 'https://notes.example.test/#x'] as $url) {
    linkContract(NotificationDeliveryActionLinks::httpsBase($url) === null,
        'Недопустимый адрес нельзя включать в ссылку подтверждения');
}

$root = dirname(__DIR__, 2);
$links = (string) file_get_contents($root . '/app/services/notifications/NotificationDeliveryActionLinks.php');
$worker = (string) file_get_contents($root . '/app/services/notifications/NotificationDeliveryWorker.php');
$runtime = (string) file_get_contents($root . '/app/services/notifications/NotificationDeliveryRuntime.php');
$webhook = (string) file_get_contents($root . '/app/services/notifications/SignedWebhookNotificationChannel.php');
$channels = [
    'email' => 'SmtpNotificationChannel.php',
    'browser' => 'WebPushNotificationChannel.php',
    'telegram' => 'BotNotificationChannel.php',
    'max' => 'BotNotificationChannel.php',
    'sms' => 'SmsGatewayNotificationChannel.php',
];

foreach ($channels as $channel => $file) {
    $source = (string) file_get_contents($root . '/app/services/notifications/' . $file);
    linkContract(str_contains($links, "'" . $channel . "'") && str_contains($source, "action_url"),
        'Для личного канала необходима ссылка подтверждения: ' . $channel);
}

linkContract(str_contains($links, "'recipient_eligible'")
    && str_contains($links, "'channel_enabled'")
    && str_contains($links, "'task_uid'")
    && str_contains($links, '/preview#token=')
    && !str_contains($links, "'webhook'"),
    'Ссылки выдаются только персональному доступному получателю');
linkContract(str_contains($worker, "->decorate(")
    && str_contains($runtime, 'new NotificationDeliveryActionLinks(')
    && !str_contains($webhook, 'action_url'),
    'Ссылку выдаёт штатный обработчик, а не общая очередь или Webhook');
linkContract(!str_contains($links, 'error_log(') && !str_contains($links, 'last_result_code'),
    'Секрет не должен записываться в журналы или результаты попыток');

echo "Ссылки подтверждения пяти личных каналов проверены\n";
