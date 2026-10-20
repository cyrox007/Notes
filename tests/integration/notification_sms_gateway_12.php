<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/services/notifications/NotificationHttpTransport.php';
require_once $root . '/app/services/notifications/NotificationSecretResolver.php';
require_once $root . '/app/services/notifications/SmsGatewayNotificationChannel.php';

use App\Services\Notifications\NotificationHttpTransport;
use App\Services\Notifications\NotificationSecretResolver;
use App\Services\Notifications\SmsGatewayNotificationChannel;

function smsCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('SMS: ' . $message);
    }
}

final class SmsContractSecret implements NotificationSecretResolver
{
    public function resolve(?string $reference): ?string
    {
        return $reference === 'env:SMS_TEST' ? str_repeat('k', 40) : null;
    }
}

final class SmsContractHttp implements NotificationHttpTransport
{
    public int $status = 202;
    public ?array $last = null;
    public function postJson(string $url, array $headers, array $payload, int $timeout = 10): array
    {
        $this->last = compact('url', 'headers', 'payload', 'timeout');
        return ['status' => $this->status, 'body' => '{}'];
    }
}

$http = new SmsContractHttp();
$channel = new SmsGatewayNotificationChannel(new SmsContractSecret(), $http);
smsCheck($channel->name() === 'sms', 'неверное имя адаптера');

$job = [
    'id' => 71,
    'body' => 'Текст личной переписки',
    'title' => 'Секретный объект',
    'secret_ref' => 'env:SMS_TEST',
    'config_json' => json_encode([
        'url' => 'https://sms.example.test/send',
        'phone' => '+79990000000',
        'timeout_seconds' => 7,
    ], JSON_THROW_ON_ERROR),
];
$result = $channel->deliver($job);
smsCheck($result->success && $result->code === 'sms_gateway_accepted',
    'шлюз должен подтвердить принятие по HTTP 202');
smsCheck(($http->last['url'] ?? '') === 'https://sms.example.test/send',
    'подменён адрес шлюза');
smsCheck(($http->last['payload']['recipient'] ?? '') === '+79990000000',
    'неверный номер получателя');
smsCheck(($http->last['payload']['delivery_id'] ?? '') === '71',
    'повторная доставка должна использовать устойчивый идентификатор');
smsCheck(($http->last['headers']['Authorization'] ?? '') === 'Bearer ' . str_repeat('k', 40),
    'секрет должен передаваться только в защищённом заголовке');
smsCheck(!str_contains($http->last['payload']['text'], $job['body'])
    && !str_contains($http->last['payload']['text'], $job['title']),
    'SMS раскрывает приватный текст');

$http->status = 429;
smsCheck($channel->deliver($job)->retryable,
    'HTTP 429 должен повторяться через общую очередь');
$http->status = 503;
smsCheck($channel->deliver($job)->retryable,
    'ошибка SMS-шлюза должна повторяться');
$http->status = 401;
smsCheck(!$channel->deliver($job)->retryable,
    'ошибка авторизации SMS не должна бесконечно повторяться');
smsCheck(!$channel->deliver([...$job, 'secret_ref' => 'env:MISSING'])->success,
    'отсутствие секрета должно блокировать доставку');

foreach ([
    ['url' => 'http://sms.example.test/send', 'phone' => '+79990000000'],
    ['url' => 'https://sms.example.test/send?token=secret', 'phone' => '+79990000000'],
    ['url' => 'https://sms.example.test/send', 'phone' => '89990000000'],
    ['url' => 'https://sms.example.test/send', 'phone' => '+7999\r\n555'],
    ['url' => 'https://sms.example.test/send', 'phone' => '+1234'],
] as $invalid) {
    $blocked = false;
    try {
        SmsGatewayNotificationChannel::configuration($invalid);
    } catch (InvalidArgumentException) {
        $blocked = true;
    }
    smsCheck($blocked, 'небезопасный URL или номер телефона принят');
}

$runtime = (string) file_get_contents($root . '/app/services/notifications/NotificationDeliveryRuntime.php');
$preferences = (string) file_get_contents($root . '/app/services/notifications/DatabaseNotificationPreferences.php');
$administration = (string) file_get_contents($root . '/app/services/notifications/NotificationExternalChannelAdministration.php');
$view = (string) file_get_contents($root . '/modules/admin/views/notifications.php');
$controller = (string) file_get_contents($root . '/modules/admin/controllers/NotificationSettingsController.php');
smsCheck(str_contains($runtime, 'new SmsGatewayNotificationChannel('),
    'SMS не подключён к общей очереди');
smsCheck(str_contains($preferences, "'sms' => true")
    && str_contains($preferences, "['telegram', 'max', 'sms']"),
    'SMS должен быть только персональным каналом');
smsCheck(str_contains($administration, 'SmsGatewayNotificationChannel::configuration')
    && str_contains($administration, 'owner_user_id')
    && str_contains($administration, 'secret_ref'),
    'SMS не проходит обязательную проверку адресата и секретов');
smsCheck(str_contains($controller, "'phone'")
    && str_contains($view, 'name="phone"')
    && str_contains($view, 'value="sms"'),
    'административная настройка SMS не подключена');

echo 'SMS-шлюз: безопасность адресата, секретов, очередь и статусы проверены' . PHP_EOL;
