<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/modules/messenger/services/MessengerE2eeIdentity.php';

use App\Services\MessengerE2eeIdentity;

$path = getenv('E2EE_MATRIX_KEY_FIXTURE');
if (!is_string($path) || $path === '' || !is_file($path)) {
    throw new RuntimeException('Отсутствуют подписанные публичные ключи настоящего Matrix SDK');
}
$keys = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
if (!is_array($keys)) {
    throw new RuntimeException('Неверный формат фикстуры ключей');
}
$userUid = '11111111-1111-4111-8111-111111111111';
$deviceId = 'DEVICE_001';
$identity = [
    'signing_key' => $keys['keys']['ed25519:' . $deviceId] ?? null,
    'agreement_key' => $keys['keys']['curve25519:' . $deviceId] ?? null,
    'device_keys' => $keys,
];
MessengerE2eeIdentity::verifyDevice($identity, $userUid, $deviceId);

$changed = $identity;
$changed['device_keys']['algorithms'][] = 'добавлено после подписи';
try {
    MessengerE2eeIdentity::verifyDevice($changed, $userUid, $deviceId);
} catch (InvalidArgumentException) {
    echo "OK: реальная подпись Matrix проверена; подмена отвергнута\n";
    exit(0);
}
throw new RuntimeException('Изменённый ключ Matrix ошибочно принят');
