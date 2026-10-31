<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/DatabaseManager.php';
require_once dirname(__DIR__, 2) . '/modules/messenger/services/MessengerE2eeRelayService.php';

use App\Services\MessengerE2eeRelayService;
use Core\DatabaseManager;

/** Сквозные проверки БД: одноразовые ключи, владение, идемпотентность и отзыв. */
function assertRelay(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('Проверка не пройдена: ' . $description);
    }
}

function mustReject(callable $operation, string $description): void
{
    try {
        $operation();
    } catch (DomainException | InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException('Запрос ошибочно разрешён: ' . $description);
}

$host = getenv('DBHOST') ?: '127.0.0.1';
$port = getenv('DBPORT') ?: '3306';
$dbname = getenv('DBNAME') ?: 'e2ee_relay_test';
$db = new PDO(
    'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';charset=utf8mb4',
    getenv('DBUSER') ?: 'root',
    getenv('DBPASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$db->exec('CREATE TABLE users (id INT NOT NULL PRIMARY KEY, uid CHAR(36) NOT NULL UNIQUE, is_active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB');
$db->exec('CREATE TABLE dialogs (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, uid CHAR(36) NOT NULL UNIQUE) ENGINE=InnoDB');
$db->exec('CREATE TABLE user_to_dialogs (
    dialog_id BIGINT UNSIGNED NOT NULL, user_id INT NOT NULL,
    is_deleted TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (dialog_id, user_id)
) ENGINE=InnoDB');
$schema = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261031_messenger_e2ee_relay.sql');
assertRelay(is_string($schema), 'файл миграции существует');
foreach (explode(';', $schema) as $statement) {
    $statement = preg_replace('/^\s*--[^\n]*$/m', '', $statement);
    if (trim($statement) !== '') {
        $db->exec($statement);
    }
}
$uids = [
    1 => '11111111-1111-4111-8111-111111111111',
    2 => '22222222-2222-4222-8222-222222222222',
    3 => '33333333-3333-4333-8333-333333333333',
];
foreach ($uids as $id => $uid) {
    $statement = $db->prepare('INSERT INTO users (id, uid) VALUES (?, ?)');
    $statement->execute([$id, $uid]);
}
$db->exec("INSERT INTO dialogs (id, uid) VALUES (7, '77777777-7777-4777-8777-777777777777')");
$db->exec('INSERT INTO user_to_dialogs (dialog_id, user_id) VALUES (7, 1), (7, 2)');

$service = new MessengerE2eeRelayService(DatabaseManager::forConnection($db));
$key1 = base64_encode(random_bytes(32));
$key2 = base64_encode(random_bytes(32));
$key3 = base64_encode(random_bytes(32));
$key4 = base64_encode(random_bytes(32));
$service->register(1, 'device_001', ['signing_key' => $key1, 'agreement_key' => $key2]);
$service->register(2, 'device_002', ['signing_key' => $key3, 'agreement_key' => $key4]);
assertRelay(count($service->devices(1, $uids[2])) === 1, 'публичные ключи общих участников');
mustReject(fn () => $service->devices(3, $uids[1]), 'выдача ключей постороннему');
mustReject(fn () => $service->register(1, 'device_001', ['signing_key' => $key1, 'agreement_key' => $key2]), 'подмена зарегистрированного устройства');
mustReject(fn () => $service->register(1, 'bad', ['signing_key' => $key1, 'agreement_key' => $key2]), 'слабый ID устройства');

$prekey = base64_encode(random_bytes(32));
$service->publishPrekeys(2, 'device_002', ['signed_curve25519:once' => ['key' => $prekey]]);
$claimed = $service->claimPrekey(1, $uids[2], 'device_002');
assertRelay(($claimed['key_id'] ?? '') === 'signed_curve25519:once', 'одноразовый ключ выдан');
assertRelay($service->claimPrekey(1, $uids[2], 'device_002') === null, 'одноразовый ключ не выдан повторно');
mustReject(fn () => $service->claimPrekey(3, $uids[2], 'device_002'), 'посторонний не получает ключи');

$envelope = [
    'algorithm' => 'm.olm.v1.curve25519-aes-sha2',
    'sender_key' => $key2,
    'ciphertext' => [$key4 => ['body' => base64_encode(random_bytes(120)), 'type' => 0]],
];
$dialogUid = '77777777-7777-4777-8777-777777777777';
$transaction = 'session_transaction_0000001';
$service->send(1, 'device_001', $uids[2], 'device_002', $dialogUid, $transaction, $envelope);
$service->send(1, 'device_001', $uids[2], 'device_002', $dialogUid, $transaction, $envelope);
assertRelay(count($service->inbox(2, 'device_002')) === 1, 'повтор не создаёт дубликат');
mustReject(fn () => $service->send(1, 'device_001', $uids[3], 'device_002', $dialogUid, 'session_transaction_0000002', $envelope), 'чужой диалог');
$changed = $envelope;
$changed['ciphertext'][$key4]['body'] = 'подмена';
mustReject(fn () => $service->send(1, 'device_001', $uids[2], 'device_002', $dialogUid, $transaction, $changed), 'повтор с изменёнными данными');
mustReject(fn () => $service->send(1, 'device_001', $uids[2], 'device_002', $dialogUid, 'session_transaction_0000003', ['plaintext' => 'secret']), 'открытый текст');
$inbox = $service->inbox(2, 'device_002');
assertRelay(count($inbox) === 1 && $inbox[0]['envelope'] == $envelope, 'передан исходный шифротекст');
mustReject(fn () => $service->inbox(1, 'device_002'), 'чтение чужого устройства');
$service->acknowledge(1, 'device_001', [$inbox[0]['id']]);
assertRelay(count($service->inbox(2, 'device_002')) === 1, 'чужой ack не удалил сообщение');
$service->acknowledge(2, 'device_002', [$inbox[0]['id']]);
assertRelay(count($service->inbox(2, 'device_002')) === 0, 'подтверждённое сообщение скрыто');
$service->send(1, 'device_001', $uids[2], 'device_002', $dialogUid, $transaction, $envelope);
assertRelay(count($service->inbox(2, 'device_002')) === 0, 'подтверждённый пакет нельзя доставить повторно');
assertRelay((int) $db->query('SELECT COUNT(*) FROM messenger_e2ee_mailbox WHERE acknowledged_at IS NOT NULL')->fetchColumn() === 1, 'подтверждение хранится как защита от повторной отправки');
$service->revoke(2, 'device_002');
mustReject(fn () => $service->inbox(2, 'device_002'), 'отозванное устройство не получает очередь');
mustReject(fn () => $service->register(2, 'device_002', ['signing_key' => $key3, 'agreement_key' => $key4]), 'повторное создание отозванного ID');
echo "OK: ключи, права, одноразовая выдача, очередь, повторы и отзыв\n";
