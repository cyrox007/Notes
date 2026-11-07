<?php

declare(strict_types=1);

use App\Services\MessengerE2eeRelayService;
use App\Services\MessengerService;
use Core\DatabaseManager;
use Core\PrefixPDO;

$root = dirname(__DIR__, 2);
require_once $root . '/core/PrefixPDO.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/core/Uuid.php';
require_once $root . '/modules/messenger/services/MessengerService.php';
require_once $root . '/modules/messenger/services/MessengerE2eeRelayService.php';

function assertMode(bool $value, string $reason): void
{
    if (!$value) {
        throw new RuntimeException('Проверка режима Messenger: ' . $reason);
    }
}

function mustRejectMode(callable $action, string $reason): void
{
    try {
        $action();
    } catch (DomainException | PDOException | InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Операция ошибочно прошла: ' . $reason);
}

$name = (string) (getenv('DBNAME') ?: 'e2ee_relay_test');
if (!str_contains(strtolower($name), 'test')) {
    throw new RuntimeException('Проверять режимы разрешено только в отдельной тестовой базе');
}
// Собственный префикс исключает столкновение с основным испытанием транспорта.
putenv('DBPREFIX=mode_');
$db = new PrefixPDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        getenv('DBHOST') ?: '127.0.0.1',
        (int) (getenv('DBPORT') ?: 3306),
        $name
    ),
    getenv('DBUSER') ?: 'root',
    getenv('DBPASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

foreach (['messenger_attachments', 'messages', 'user_to_dialogs', 'dialogs', 'users'] as $table) {
    $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
}
$db->exec('CREATE TABLE `users` (
    id INT NOT NULL PRIMARY KEY,
    uid CHAR(36) NOT NULL UNIQUE,
    username VARCHAR(90) NOT NULL,
    firstname VARCHAR(90) NOT NULL,
    lastname VARCHAR(90) NOT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    is_active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB');
$db->exec("CREATE TABLE `dialogs` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid CHAR(36) NOT NULL UNIQUE,
    type ENUM('private','group','saved') NOT NULL DEFAULT 'private',
    name VARCHAR(120) DEFAULT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");
$db->exec("CREATE TABLE `user_to_dialogs` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dialog_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_read_message_id BIGINT UNSIGNED DEFAULT NULL,
    is_deleted TINYINT NOT NULL DEFAULT 0,
    UNIQUE KEY (dialog_id,user_id)
) ENGINE=InnoDB");
$db->exec("CREATE TABLE `messages` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dialog_id BIGINT UNSIGNED NOT NULL,
    message TEXT NOT NULL
) ENGINE=InnoDB");
$db->exec("CREATE TABLE `messenger_attachments` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dialog_id BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB");

$sql = (string) file_get_contents(
    $root . '/database/migrations/20261107_messenger_e2ee_dialog_mode.sql'
);
foreach (explode(';', $sql) as $statement) {
    $statement = trim((string) preg_replace('/^\s*--[^\n]*$/m', '', $statement));
    if ($statement !== '') {
        $db->exec($statement);
    }
}

$uid1 = '11111111-1111-4111-8111-111111111111';
$uid2 = '22222222-2222-4222-8222-222222222222';
$db->prepare('INSERT INTO users (id,uid,username,firstname,lastname) VALUES (?,?,?,?,?)')
    ->execute([1,$uid1,'user1','Первый','Тест']);
$db->prepare('INSERT INTO users (id,uid,username,firstname,lastname) VALUES (?,?,?,?,?)')
    ->execute([2,$uid2,'user2','Второй','Тест']);

$manager = DatabaseManager::forConnection($db);
$legacy = new MessengerService($manager);
$relay = new MessengerE2eeRelayService($manager);
$plain = $legacy->createDialog($uid1, 'private', [$uid2]);
assertMode($plain['encryption_mode'] === 'server_encrypted',
    'старые диалоги должны оставаться в обычном режиме');
$protected = $relay->createEncryptedDialog(1, $uid2);
assertMode($protected['encryption_mode'] === 'e2ee',
    'отдельный защищённый диалог должен иметь серверный режим E2EE');
assertMode($plain['uid'] !== $protected['uid'],
    'обычную историю нельзя тихо превратить в E2EE');
$again = $relay->createEncryptedDialog(1, $uid2);
assertMode($protected['uid'] === $again['uid'],
    'повторное создание не должно создавать параллельные защищённые диалоги');
assertMode($legacy->createDialog($uid1, 'private', [$uid2])['uid'] === $plain['uid'],
    'старый маршрут не должен выбирать защищённый диалог');

$plainId = (int) $db->query(
    "SELECT id FROM dialogs WHERE encryption_mode='server_encrypted' LIMIT 1"
)->fetchColumn();
$protectedId = (int) $db->query(
    "SELECT id FROM dialogs WHERE encryption_mode='e2ee' LIMIT 1"
)->fetchColumn();
assertMode($plainId > 0 && $protectedId > 0 && $plainId !== $protectedId,
    'обе комнаты присутствуют в БД раздельно');
$db->exec('INSERT INTO messages (dialog_id,message) VALUES (' . $plainId . ",'обычная запись')");
$db->exec('INSERT INTO messenger_attachments (dialog_id) VALUES (' . $plainId . ')');
mustRejectMode(
    fn () => $db->exec('INSERT INTO messages (dialog_id,message) VALUES (' . $protectedId . ",'НЕДОПУСТИМЫЙ ТЕКСТ')"),
    'БД не должна принимать текст старого транспорта в E2EE'
);
mustRejectMode(
    fn () => $db->exec('INSERT INTO messenger_attachments (dialog_id) VALUES (' . $protectedId . ')'),
    'БД не должна принимать обычное вложение в E2EE'
);
mustRejectMode(
    fn () => $db->exec("UPDATE dialogs SET encryption_mode='server_encrypted' WHERE id=" . $protectedId),
    'режим существующего E2EE-диалога нельзя изменить'
);
mustRejectMode(
    fn () => $db->exec("UPDATE dialogs SET encryption_mode='e2ee' WHERE id=" . $plainId),
    'старый диалог нельзя представить как E2EE'
);
mustRejectMode(
    fn () => $legacy->sendMessage($uid1, $protected['uid'], 'Обычный текст'),
    'серверный обработчик обычной отправки должен отказать'
);
mustRejectMode(
    fn () => $relay->participants(1, $plain['uid']),
    'E2EE нельзя открыть через транспорт обычного диалога'
);
assertMode(count($relay->participants(1, $protected['uid'])['members']) === 2,
    'оба участника защищённого диалога должны иметь доступ к составу');
assertMode((int) $db->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 1,
    'неуспешная обычная отправка не сохранила сообщение');

echo "[OK] Раздельные режимы Messenger, неизменяемый диалог, запрет старого транспорта, DBPREFIX\n";
