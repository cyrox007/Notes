<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/services/notifications/WebPushCrypto.php';
require_once $root . '/app/services/notifications/PushSubscriptionService.php';

use App\Services\Notifications\WebPushCrypto;
use App\Services\Notifications\PushSubscriptionService;

function pushCheck(bool $valid, string $message): void
{
    if (!$valid) {
        throw new RuntimeException('Web Push: ' . $message);
    }
}

$key = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
pushCheck($key instanceof OpenSSLAsymmetricKey, 'не удалось подготовить P-256 ключ');
openssl_pkey_export($key, $privatePem);
putenv('NOTES_VAPID_PRIVATE_KEY_BASE64=' . base64_encode($privatePem));
putenv('NOTES_VAPID_CONTACT=mailto:push@example.test');

$public = WebPushCrypto::publicApplicationKey();
pushCheck(strlen(WebPushCrypto::decode64($public)) === 65, 'неверный публичный VAPID ключ');
pushCheck(WebPushCrypto::base64url(WebPushCrypto::decode64($public)) === $public,
    'неканонический формат VAPID');
pushCheck(WebPushCrypto::point($key) === WebPushCrypto::decode64($public),
    'публичный ключ приложения не совпадает с приватным');

$headers = WebPushCrypto::headers('https://push.example.test/p/123');
pushCheck(($headers['TTL'] ?? '') === '120' && ($headers['Content-Encoding'] ?? '') === 'aes128gcm',
    'неверные Web Push заголовки');
pushCheck(str_starts_with((string) $headers['Authorization'], 'vapid t='),
    'неверная схема авторизации VAPID');
preg_match('/^vapid t=([A-Za-z0-9_.-]+), k=([A-Za-z0-9_-]+)$/D', $headers['Authorization'], $matches);
pushCheck(count($matches) === 3 && $matches[2] === $public,
    'публичный VAPID ключ не указан в авторизации');
$segments = explode('.', $matches[1]);
pushCheck(count($segments) === 3, 'токен VAPID не является подписанным JWT');
$claims = json_decode(WebPushCrypto::decode64($segments[1]), true, 16, JSON_THROW_ON_ERROR);
pushCheck(($claims['aud'] ?? '') === 'https://push.example.test'
    && ($claims['sub'] ?? '') === 'mailto:push@example.test'
    && ($claims['exp'] ?? 0) > time(), 'неверные обязательные утверждения JWT');
pushCheck(strlen(WebPushCrypto::decode64($segments[2])) === 64, 'неверный формат подписи ES256');

$receiver = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
pushCheck($receiver instanceof OpenSSLAsymmetricKey, 'не удалось подготовить ключ браузера');
$receiverPublic = WebPushCrypto::point($receiver);
$auth = random_bytes(16);
$payload = '{"id":19,"path":"/messenger/7"}';
$encrypted = WebPushCrypto::encrypt($payload, $receiverPublic, $auth);
pushCheck(strlen($encrypted) > 102 && substr($encrypted, 16, 4) === pack('N', 4096)
    && ord($encrypted[20]) === 65, 'неверный aes128gcm заголовок');

$salt = substr($encrypted, 0, 16);
$senderPublic = substr($encrypted, 21, 65);
$cipherAndTag = substr($encrypted, 86);
$body = substr($cipherAndTag, 0, -16);
$tag = substr($cipherAndTag, -16);
$shared = openssl_pkey_derive(WebPushCrypto::publicFromBytes($senderPublic), $receiver, 32);
pushCheck(is_string($shared) && strlen($shared) === 32, 'ECDH не согласован');

// Независимо раскрываем стандартный HKDF RFC 8291 и проверяем GCM-тег.
$authExtract = hash_hmac('sha256', $shared, $auth, true);
$info = "WebPush: info\0" . $receiverPublic . $senderPublic;
$ikm = hash_hmac('sha256', $info . "\x01", $authExtract, true);
$prk = hash_hmac('sha256', $ikm, $salt, true);
$cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
$nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
$decoded = openssl_decrypt($body, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
pushCheck($decoded === $payload . "\x02", 'RFC 8291 сообщение не расшифровывается');

foreach ([
    'http://push.example.test/sub',
    'https://localhost/sub',
    'https://user:pass@push.example.test/sub',
    'https://push.example.test:8443/sub',
    'https://push.example.test/sub#fragment',
] as $bad) {
    $blocked = false;
    try {
        PushSubscriptionService::endpoint($bad);
    } catch (InvalidArgumentException) {
        $blocked = true;
    }
    pushCheck($blocked, 'небезопасный адрес подписки принят');
}
foreach (['%bad', '!!!', 'a=', ''] as $bad) {
    $blocked = false;
    try {
        WebPushCrypto::decode64($bad);
    } catch (InvalidArgumentException) {
        $blocked = true;
    }
    pushCheck($blocked, 'некорректный ключ браузера принят');
}

$worker = (string) file_get_contents($root . '/service-worker.js');
$runtime = (string) file_get_contents($root . '/app/services/notifications/NotificationDeliveryRuntime.php');
$routes = (string) file_get_contents($root . '/core/routerConfig.php');
pushCheck(str_contains($runtime, 'new WebPushNotificationChannel('),
    'не зарегистрирован обработчик доставки Browser Push');
pushCheck(str_contains($routes, 'CSRFMiddleware::class') && str_contains($routes, "'/push/subscribe'"),
    'маршрут браузерной регистрации не защищён CSRF');
pushCheck(!str_contains($worker, 'caches.open(') && !str_contains($worker, 'fetch('),
    'фоновый обработчик не должен кэшировать пользовательские ответы');
echo "Проверено шифрование RFC 8291, заголовки VAPID, маршруты и защита Push\n";
