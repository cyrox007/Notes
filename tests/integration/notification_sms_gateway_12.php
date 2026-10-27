<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/services/notifications/NotificationHttpTransport.php';
require_once $root . '/app/services/notifications/NotificationSecretResolver.php';
require_once $root . '/app/services/notifications/SmsGatewayNotificationChannel.php';
require_once $root . '/app/services/notifications/NotificationExternalChannelAdministration.php';

use App\Services\Notifications\NotificationHttpTransport;
use App\Services\Notifications\NotificationSecretResolver;
use App\Services\Notifications\SmsGatewayNotificationChannel;
use App\Services\Notifications\HeadwindSmsTransport;
use App\Services\Notifications\NotificationExternalChannelAdministration;
use Core\DatabaseManager;

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
    && str_contains($preferences, "['telegram', 'max', 'sms', 'browser']"),
    'SMS должен быть только персональным каналом');
smsCheck(str_contains($administration, 'SmsGatewayNotificationChannel::configuration')
    && str_contains($administration, 'owner_user_id')
    && str_contains($administration, 'secret_ref'),
    'SMS не проходит обязательную проверку адресата и секретов');
smsCheck(str_contains($controller, "'phone'")
    && str_contains($view, 'name="phone"')
    && str_contains($view, 'value="sms"'),
    'административная настройка SMS не подключена');

final class HeadwindTestDriver
{
    public string $StatusStr = 'ready';
    public int $connections = 0;
    public function Connect(): void { $this->connections++; $this->StatusStr = 'ready'; }
}
final class HeadwindTestMessage
{
    public string $To = '';
    public string $Body = '';
    public int $Flags = -1;
    public int $sends = 0;
    public bool $throw = false;
    public function Send(): void
    {
        $this->sends++;
        if ($this->throw) { throw new RuntimeException('Sensitive COM exception'); }
    }
}
$driver = new HeadwindTestDriver();
$message = new HeadwindTestMessage();
$created = [];
$headwind = new HeadwindSmsTransport(static function (string $id) use ($driver, $message, &$created): object {
    $created[] = $id;
    return $id === 'HeadwindGSM.SMSDriver' ? $driver : $message;
});
$unusedSecret = new class implements NotificationSecretResolver {
    public function resolve(?string $reference): ?string { throw new LogicException('Headwind must not resolve API secrets'); }
};
$http->last = null;
$local = new SmsGatewayNotificationChannel($unusedSecret, $http, $headwind);
$localJob = [...$job, 'config_json' => json_encode(['transport' => 'headwind', 'phone' => '+79990000000']), 'secret_ref' => null];
$result = $local->deliver($localJob);
smsCheck($result->success && $result->code === 'sms_headwind_accepted', 'Headwind должен принять сообщение');
smsCheck($http->last === null && $created === ['HeadwindGSM.SMSDriver', 'HeadwindGSM.SMSMessage'], 'Headwind должен использовать COM без HTTP');
smsCheck($message->To === '79990000000' && $message->sends === 1 && $message->Flags === 0, 'неверный вызов драйвера');
smsCheck(str_contains($message->Body, 'Notes:') && !str_contains($message->Body, $job['body']) && !str_contains($message->Body, $job['title']), 'SMS раскрывает приватные данные');
$url = 'https://notes.example.test/notification-action/test';
$local->deliver([...$localJob, 'action_url' => $url]);
smsCheck(str_contains($message->Body, $url), 'ссылка подтверждения задачи потеряна');
$driver->StatusStr = 'disconnected';
smsCheck($local->deliver($localJob)->success && $driver->connections === 1, 'отключённый драйвер должен подключиться');
foreach (['busy', 'configuring', 'error', 'unknown'] as $state) {
    $driver->StatusStr = $state;
    $before = $message->sends;
    $result = $local->deliver($localJob);
    smsCheck(!$result->success && $result->retryable && $message->sends === $before, 'неготовый модем не должен получать SMS');
}
$driver->StatusStr = 'ready';
$message->throw = true;
$result = $local->deliver($localJob);
smsCheck(!$result->success && !$result->retryable && $result->code === 'sms_headwind_send_unknown', 'неопределённый результат Send нельзя повторять автоматически');
smsCheck(!str_contains($result->errorSummary ?? '', 'Sensitive'), 'ошибка COM не должна попадать в журнал');
$missing = new HeadwindSmsTransport(static fn (string $id): object => throw new RuntimeException('Class not registered'));
smsCheck($missing->send('+79990000000', 'test')->code === 'sms_headwind_driver_unavailable', 'нет диагностики регистрации COM');
if (!HeadwindSmsTransport::environment()['available']) {
    smsCheck((new HeadwindSmsTransport())->send('+79990000000', 'test')->code === 'sms_headwind_unavailable', 'неподдерживаемое окружение не заблокировано');
}
smsCheck(SmsGatewayNotificationChannel::configuration(['phone' => '+79990000000'])['transport'] === 'headwind', 'новая конфигурация должна использовать Headwind');
try {
    SmsGatewayNotificationChannel::configuration(['transport' => 'unknown', 'phone' => '+79990000000']);
    smsCheck(false, 'неизвестный транспорт принят');
} catch (InvalidArgumentException) {}

// Real persistence in an isolated in-memory database; no installed data or modem.
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,is_active INTEGER,account_status TEXT,deletion_requested_at TEXT,anonymized_at TEXT)');
$pdo->exec("INSERT INTO users VALUES (1,1,'active',NULL,NULL),(2,0,'active',NULL,NULL)");
$pdo->exec('CREATE TABLE notification_channel_configs (id INTEGER PRIMARY KEY,channel TEXT,owner_user_id INTEGER,owner_scope INTEGER,label TEXT,enabled INTEGER,config_json TEXT,secret_ref TEXT)');
$service = new NotificationExternalChannelAdministration(DatabaseManager::forConnection($pdo));
$service->save(['channel' => 'sms', 'owner_user_id' => 1, 'phone' => '+79990000000', 'enabled' => true]);
$stored = $pdo->query('SELECT * FROM notification_channel_configs')->fetch(PDO::FETCH_ASSOC);
smsCheck($stored['secret_ref'] === null && json_decode($stored['config_json'], true)['transport'] === 'headwind', 'локальный SMS требует лишний секрет или не сохранился');
$pdo->exec("UPDATE notification_channel_configs SET secret_ref='env:OLD_SMS_KEY', config_json='{}'");
$service->save(['channel' => 'sms', 'owner_user_id' => 1, 'phone' => '+79991111111', 'enabled' => false]);
$storedAgain = $pdo->query('SELECT * FROM notification_channel_configs')->fetch(PDO::FETCH_ASSOC);
smsCheck($storedAgain['id'] === $stored['id'] && $storedAgain['secret_ref'] === null && (int) $storedAgain['enabled'] === 0, 'перенастройка должна обновить прежний канал и убрать API-секрет');
try {
    $service->save(['channel' => 'sms', 'owner_user_id' => 2, 'phone' => '+79991111111', 'enabled' => true]);
    smsCheck(false, 'отключённый получатель принят');
} catch (InvalidArgumentException) {}
smsCheck((int) $pdo->query('SELECT COUNT(*) FROM notification_channel_configs')->fetchColumn() === 1, 'создан лишний адресат');

echo 'SMS: Headwind COM, секреты, сохранение, очередь и совместимость HTTPS проверены' . PHP_EOL;
