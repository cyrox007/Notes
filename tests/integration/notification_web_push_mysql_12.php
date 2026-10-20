<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('TEST_DB_PORT') ?: 3306);
$name = getenv('TEST_DB_NAME') ?: 'workspace_test';
$user = getenv('TEST_DB_USER') ?: 'workspace';
$pass = getenv('TEST_DB_PASS') ?: 'workspace';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($host, $user, $pass, $name, $port);
$mysqli->set_charset('utf8mb4');

function pushMysqlCheck(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException('MySQL Web Push: ' . $message);
    }
}
function pushMysqlSchema(mysqli $db, string $filename): void
{
    $source = file_get_contents($filename);
    if (!is_string($source)) throw new RuntimeException('Не прочитана схема ' . $filename);
    $source = preg_replace('/^[ \t]*--[^\r\n]*(?:\r?\n|$)/m', '', $source) ?? '';
    foreach (explode(';', $source) as $part) {
        $statement = trim($part);
        if ($statement !== '') $db->query($statement);
    }
}

pushMysqlSchema($mysqli, $root . '/database/core_identity_schema.sql');
pushMysqlSchema($mysqli, $root . '/database/notifications_schema.sql');
pushMysqlSchema($mysqli, $root . '/database/migrations/20261020_notifications_push_subscriptions.sql');

$suffix = bin2hex(random_bytes(6));
$insertUser = $mysqli->prepare(
    "INSERT INTO users(uid,username,email,password_hash,firstname,lastname,role,is_active,account_status)
     VALUES (?,?,?,'test','Push','Испытание',888,1,'active')"
);
$ids = [];
foreach ([1, 2] as $index) {
    $uid = sprintf('eeeeeeee-eeee-4eee-8eee-%012d', random_int(1, 999999999999));
    $username = 'push-test-' . $index . '-' . $suffix;
    $email = $username . '@example.test';
    $insertUser->bind_param('sss', $uid, $username, $email);
    $insertUser->execute();
    $ids[] = (int) $mysqli->insert_id;
}
$insertUser->close();
[$firstUser, $secondUser] = $ids;

putenv('DBDRIVER=mysql');
putenv('DBHOST=' . $host);
putenv('DBPORT=' . $port);
putenv('DBNAME=' . $name);
putenv('DBUSER=' . $user);
putenv('DBPASS=' . $pass);
putenv('NOTES_VAPID_CONTACT=mailto:push@example.test');
require_once $root . '/core/Config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/app/services/notifications/PushSubscriptionService.php';
require_once $root . '/app/services/notifications/WebPushNotificationChannel.php';
require_once $root . '/app/services/notifications/NotificationService.php';
require_once $root . '/app/services/notifications/NotificationEvent.php';
require_once $root . '/app/services/notifications/DatabaseNotificationStore.php';

use App\Services\Notifications\PushSubscriptionService;
use App\Services\Notifications\WebPushCrypto;
use App\Services\Notifications\WebPushHttpTransport;
use App\Services\Notifications\WebPushNotificationChannel;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\DatabaseNotificationStore;

\Core\DatabaseManager::resetInstance();
$db = \Core\DatabaseManager::getInstance();


$private = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC, 'curve_name'=>'prime256v1']);
pushMysqlCheck($private instanceof OpenSSLAsymmetricKey, 'не создан VAPID-ключ');
openssl_pkey_export($private, $pem);
putenv('NOTES_VAPID_PRIVATE_KEY_BASE64=' . base64_encode($pem));

$browser = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC, 'curve_name'=>'prime256v1']);
pushMysqlCheck($browser instanceof OpenSSLAsymmetricKey, 'не создан браузерный ключ');
$deviceKeys = [
    'p256dh' => WebPushCrypto::base64url(WebPushCrypto::point($browser)),
    'auth' => WebPushCrypto::base64url(random_bytes(16)),
];
$endpoint1 = 'https://push.example.test/notify/' . $suffix . '/1';
$endpoint2 = 'https://push.example.test/notify/' . $suffix . '/2';
$service = new PushSubscriptionService($db);

$first = $service->subscribe($firstUser, ['endpoint'=>$endpoint1,'keys'=>$deviceKeys]);
$repeat = $service->subscribe($firstUser, ['endpoint'=>$endpoint1,'keys'=>$deviceKeys]);
pushMysqlCheck($first['id'] === $repeat['id'] && $repeat['active'] === 1,
    'повтор регистрации должен быть идемпотентным');
pushMysqlCheck($service->ownsHash($firstUser, $first['endpoint_hash']),
    'устройство не закреплено за пользователем');

$blocked = false;
try { $service->subscribe($secondUser, ['endpoint'=>$endpoint1,'keys'=>$deviceKeys]); }
catch (DomainException) { $blocked = true; }
pushMysqlCheck($blocked && !$service->ownsHash($secondUser, $first['endpoint_hash']),
    'чужая учётная запись не должна перехватывать endpoint');

$second = $service->subscribe($firstUser, ['endpoint'=>$endpoint2,'keys'=>$deviceKeys]);
pushMysqlCheck($second['active'] === 2, 'второе устройство не зарегистрировано');
$other = $service->subscribe($secondUser, [
    'endpoint'=>'https://push.example.test/notify/' . $suffix . '/other',
    'keys'=>$deviceKeys,
]);
pushMysqlCheck($other['active'] === 1, 'личные подписки не должны смешиваться');

$configIds = $db->fetchAll(
    "SELECT id FROM notification_channel_configs
     WHERE channel='browser' AND owner_user_id=:uid AND enabled=1 ORDER BY id",
    [':uid'=>$firstUser]
);
pushMysqlCheck(count($configIds) === 2, 'на каждое устройство нужен отдельный адрес доставки');

$db->execute(
    "INSERT INTO notification_subscriptions
     (user_id, category, enabled, in_app_enabled, browser_enabled)
     VALUES (:uid, 'tasks.deadlines', 1, 1, 1)
     ON DUPLICATE KEY UPDATE browser_enabled=1,enabled=1",
    [':uid'=>$firstUser]
);
$notificationService = new NotificationService(new DatabaseNotificationStore($db));
$event = fn (string $id): NotificationEvent => new NotificationEvent(
    name: 'tasks.push_test',
    version: 1,
    idempotencyKey: 'mysql-push:' . $suffix . ':' . $id,
    source: 'tasks',
    category: 'tasks.deadlines',
    importance: 'normal',
    title: 'Не раскрывать название',
    body: 'Не раскрывать содержимое задачи',
    recipientUserIds: [$firstUser],
    targetPath: '/tasks'
);
$publication = $notificationService->publish($event('one'));
$eventId = (int) $publication['event_id'];
$jobs = $db->fetchAll(
    "SELECT j.id,j.channel_config_id,j.notification_id
     FROM notification_delivery_jobs j JOIN notifications n ON n.id=j.notification_id
     WHERE n.event_id=:id AND j.channel='browser' ORDER BY j.id",
    [':id'=>$eventId]
);
pushMysqlCheck(count($jobs) === 2, 'два устройства должны получить два независимых задания');
pushMysqlCheck($jobs[0]['channel_config_id'] !== $jobs[1]['channel_config_id'],
    'очередь не должна объединять разные устройства');

final class WebPushMysqlHttp implements WebPushHttpTransport
{
    public int $code = 201;
    public ?array $last = null;
    public function send(string $endpoint, string $body, array $headers): int
    {
        $this->last = compact('endpoint','body','headers');
        return $this->code;
    }
}
$http = new WebPushMysqlHttp();
$sender = new WebPushNotificationChannel($service, $http);
$sent = $sender->deliver([
    'recipient_user_id'=>$firstUser,
    'channel_config_id'=>(int) $jobs[0]['channel_config_id'],
    'notification_id'=>(int) $jobs[0]['notification_id'],
    'target_path'=>'/tasks',
]);
pushMysqlCheck($sent->success && $sent->code === 'push_accepted',
    'тестовый Push-сервис не принял зашифрованное сообщение');
pushMysqlCheck(!str_contains((string) $http->last['body'], 'Не раскрывать')
    && strlen($http->last['body']) > 100
    && str_starts_with($http->last['headers']['Authorization'], 'vapid t='),
    'Web Push должен отправлять только шифротекст и VAPID');

pushMysqlCheck($service->revoke($firstUser, $endpoint1), 'отзыв своего устройства не выполнился');
pushMysqlCheck(!$service->revoke($firstUser, $endpoint1), 'повторный отзыв должен быть безопасен');
pushMysqlCheck(!$service->ownsHash($firstUser, $first['endpoint_hash']),
    'отозванное устройство осталось активным');
pushMysqlCheck($service->ownsHash($firstUser, $second['endpoint_hash']),
    'отзыв одного устройства не должен блокировать другое');
pushMysqlCheck($service->subscriptionForJob($firstUser, (int) $configIds[0]['id']) === null,
    'старое задание нельзя доставить на отозванный endpoint');

$next = $notificationService->publish($event('two'));
$activeJobs = (int) $db->fetchValue(
    "SELECT COUNT(*) FROM notification_delivery_jobs j
     JOIN notifications n ON n.id=j.notification_id
     WHERE n.event_id=:id AND j.channel='browser'",
    [':id'=>(int) $next['event_id']]
);
pushMysqlCheck($activeJobs === 1, 'после отзыва новое событие адресуется только живому устройству');

$remaining = (int) $configIds[1]['id'];
$http->code = 410;
$expired = $sender->deliver([
    'recipient_user_id'=>$firstUser,
    'channel_config_id'=>$remaining,
    'notification_id'=>700,
    'target_path'=>'/tasks',
]);
pushMysqlCheck(!$expired->success && $expired->code === 'push_expired'
    && !$service->ownsHash($firstUser, $second['endpoint_hash']),
    'push-сервис 410 должен отозвать неподдерживаемое устройство');

$db->execute('DELETE FROM users WHERE id=:id', [':id'=>$firstUser]);
$db->execute('DELETE FROM users WHERE id=:id', [':id'=>$secondUser]);
\Core\DatabaseManager::resetInstance();
$mysqli->close();
echo "Проверена MySQL-привязка нескольких устройств, изоляция, очередь, отзыв и 410\n";
