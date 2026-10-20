<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/services/notifications/NotificationHttpTransport.php';
require_once $root . '/app/services/notifications/NotificationSecretResolver.php';
require_once $root . '/app/services/notifications/SignedWebhookNotificationChannel.php';
require_once $root . '/app/services/notifications/BotNotificationChannel.php';

use App\Services\Notifications\NotificationHttpTransport;
use App\Services\Notifications\NotificationSecretResolver;
use App\Services\Notifications\SignedWebhookNotificationChannel;
use App\Services\Notifications\BotNotificationChannel;

function externalCheck(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('Внешние каналы: ' . $description);
    }
}

final class ChannelContractSecret implements NotificationSecretResolver
{
    public function resolve(?string $reference): ?string
    {
        return match ($reference) {
            'env:WEBHOOK_TEST' => str_repeat('s', 40),
            'env:TELEGRAM_TEST' => '12345:' . str_repeat('A', 35),
            'env:MAX_TEST' => str_repeat('X', 48),
            default => null,
        };
    }
}

final class ChannelContractHttp implements NotificationHttpTransport
{
    public ?array $last = null;
    public int $status = 200;
    public string $body = '{"ok":true}';

    public function postJson(string $url, array $headers, array $payload, int $timeout = 10): array
    {
        $this->last = compact('url', 'headers', 'payload', 'timeout');
        return ['status' => $this->status, 'body' => $this->body];
    }
}

$http = new ChannelContractHttp();
$secrets = new ChannelContractSecret();
$webhook = new SignedWebhookNotificationChannel($secrets, $http);
$job = [
    'id' => 112,
    'notification_id' => 120,
    'event_name' => 'task.due_soon',
    'event_version' => 1,
    'category' => 'tasks',
    'importance' => 'normal',
    'title' => 'Секретный текст задачи',
    'body' => 'Секретный текст сообщения Messenger',
    'config_json' => json_encode(['url' => 'https://webhook.example.test/delivery']),
    'secret_ref' => 'env:WEBHOOK_TEST',
];
$result = $webhook->deliver($job);
externalCheck($result->success, 'корректный подписанный Webhook должен отправляться');
externalCheck($http->last['url'] === 'https://webhook.example.test/delivery', 'нельзя подменять URL');
externalCheck(!in_array($job['title'], $http->last['payload'], true)
    && !in_array($job['body'], $http->last['payload'], true),
    'Webhook не должен передавать содержимое пользовательских объектов');
$encoded = json_encode($http->last['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$expected = 'sha256=' . hash_hmac(
    'sha256', $http->last['headers']['X-Notes-Timestamp'] . "\n" . $encoded,
    str_repeat('s', 40)
);
externalCheck($http->last['headers']['X-Notes-Signature'] === $expected,
    'подпись Webhook не совпадает с отправленным телом');
$http->status = 503;
externalCheck($webhook->deliver($job)->retryable, 'ошибка сервера Webhook должна повторяться');
$http->status = 401;
externalCheck(!$webhook->deliver($job)->retryable, '401 не должен бесконечно повторяться');

foreach ([
    ['url' => 'http://example.com/hook'],
    ['url' => 'https://secret@example.com/hook'],
    ['url' => 'https://webhook.example.test/hook?token=secret'],
] as $invalid) {
    $rejected = false;
    try {
        SignedWebhookNotificationChannel::configuration($invalid);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    externalCheck($rejected, 'опасный URL должен отклоняться');
}

$http->status = 200;
$http->body = '{"ok":true}';
$telegram = new BotNotificationChannel('telegram', $secrets, $http);
$result = $telegram->deliver([
    ...$job, 'config_json' => '{"chat_id":"123456789"}', 'secret_ref' => 'env:TELEGRAM_TEST',
]);
externalCheck($result->success && $result->code === 'telegram_accepted',
    'Telegram должен принимать подтверждённый ответ');
externalCheck(str_starts_with($http->last['url'], 'https://api.telegram.org/bot'),
    'Telegram не должен использовать сторонний API');
externalCheck($http->last['payload']['chat_id'] === '123456789',
    'Telegram должен использовать личного получателя');
externalCheck(!str_contains($http->last['payload']['text'], $job['body']),
    'Telegram не должен передавать текст сообщения');
$http->body = '{"ok":false}';
externalCheck(!$telegram->deliver([
    ...$job, 'config_json' => '{"chat_id":"123456789"}', 'secret_ref' => 'env:TELEGRAM_TEST',
])->success, 'Telegram с ok=false не должен помечать отправку успешной');

$http->body = '{}';
$max = new BotNotificationChannel('max', $secrets, $http);
$result = $max->deliver([
    ...$job, 'config_json' => '{"user_id":"456789"}', 'secret_ref' => 'env:MAX_TEST',
]);
externalCheck($result->success && $result->code === 'max_accepted',
    'MAX должен отправлять только при успешном HTTP-ответе');
externalCheck($http->last['url'] === 'https://platform-api2.max.ru/messages?user_id=456789',
    'MAX должен использовать актуальный официальный URL');
externalCheck($http->last['headers']['Authorization'] === str_repeat('X', 48),
    'MAX должен передавать токен только в заголовке, не в URL');
externalCheck(!str_contains($http->last['payload']['text'], $job['body']),
    'MAX не должен раскрывать личный текст');

foreach (['telegram' => ['chat_id' => 'abc'], 'max' => ['user_id' => '-1']] as $channel => $data) {
    $rejected = false;
    try {
        BotNotificationChannel::configuration($channel, $data);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    externalCheck($rejected, 'неверный адресат не отклонён');
}

$transport = file_get_contents($root . '/app/services/notifications/CurlNotificationHttpTransport.php');
$runtime = file_get_contents($root . '/app/services/notifications/NotificationDeliveryRuntime.php');
$preferences = file_get_contents($root . '/app/services/notifications/DatabaseNotificationPreferences.php');
$routes = file_get_contents($root . '/modules/admin/AdminRuntimeProvider.php');
externalCheck(is_string($transport) && str_contains($transport, 'CURLOPT_RESOLVE')
    && str_contains($transport, 'CURLOPT_SSL_VERIFYPEER')
    && str_contains($transport, 'FILTER_FLAG_NO_PRIV_RANGE')
    && str_contains($transport, 'CURLOPT_FOLLOWLOCATION => false'),
    'HTTPS-каналы должны запрещать DNS-rebinding, небезопасный TLS и редиректы');
foreach (['telegram', 'max', 'SignedWebhookNotificationChannel'] as $channel) {
    externalCheck(str_contains((string) $runtime, $channel),
        'runtime должен регистрировать внешний канал ' . $channel);
}
externalCheck(str_contains((string) $preferences, "'max' => 'max_enabled'")
    && str_contains((string) $preferences, 'owner_user_id'),
    'личные адресаты MAX/Telegram не должны превращаться в глобальных');
externalCheck(str_contains((string) $routes, 'RequireAdminSettingsManage::class, CSRFMiddleware::class'),
    'настройка внешних каналов требует RBAC и CSRF');
$manifest = json_decode((string) file_get_contents($root . '/database/migrations/manifest.json'), true);
externalCheck(in_array('20261020_notifications_max_channel.sql', $manifest['migrations'] ?? [], true),
    'отдельная миграция MAX должна быть в каноническом манифесте');

echo "Webhook, Telegram и MAX: безопасность, формат доставки и подключение к очереди проверены\n";
