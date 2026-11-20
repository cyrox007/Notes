<?php

declare(strict_types=1);

// Проверка выполняется только на одноразовой БД испытаний.
$root = dirname(__DIR__, 2);
if (!is_file($root . '/.env')) {
    throw new RuntimeException('Нет конфигурации испытательного стенда');
}
require_once $root . '/core/Environment.php';
require_once $root . '/core/PrefixPDO.php';
require_once $root . '/modules/messenger/handlers/MessengerCrypto.php';

\Core\Environment::load($root . '/.env');
$dialogUid = (string) ($argv[1] ?? '');
$messages = json_decode((string) ($argv[2] ?? ''), true, 64, JSON_THROW_ON_ERROR);
if ($dialogUid === '' || !is_array($messages) || $messages === []) {
    throw new InvalidArgumentException('Не заданы проверяемые сообщения');
}

$pdo = new \Core\PrefixPDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        \Core\Environment::get('DBHOST'),
        (int) \Core\Environment::get('DBPORT'),
        \Core\Environment::get('DBNAME')
    ),
    \Core\Environment::get('DBUSER'),
    \Core\Environment::get('DBPASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$query = $pdo->prepare(
    'SELECT m.uid, m.message, m.message_status, u.uid AS sender_uid
     FROM messages m
     INNER JOIN dialogs d ON d.id = m.dialog_id
     INNER JOIN users u ON u.id = m.from_user_id
     WHERE d.uid = :dialog_uid AND m.is_deleted = 0
     ORDER BY m.id ASC'
);
$query->execute([':dialog_uid' => $dialogUid]);
$rows = $query->fetchAll();
if (count($rows) !== count($messages)) {
    throw new RuntimeException('Количество записей MySQL не совпало: '
        . count($rows) . ' вместо ' . count($messages));
}
foreach ($messages as $index => $expected) {
    $row = $rows[$index];
    if ($row['uid'] !== ($expected['uid'] ?? '')
        || $row['sender_uid'] !== ($expected['sender'] ?? '')) {
        throw new RuntimeException('Несоответствие идентификаторов сохранённого сообщения');
    }
    if (!str_starts_with((string) $row['message'], 'v2:')) {
        throw new RuntimeException('В БД отсутствует защищённый формат сообщения');
    }
    if (\App\Helpers\MessengerCrypto::decrypt((string) $row['message'], $row['uid'])
        !== ($expected['text'] ?? null)) {
        throw new RuntimeException('Расшифрованное сообщение не совпадает с отправленным');
    }
}
echo '[OK] MySQL, количество записанных сообщений: ' . count($rows)
    . "; UID и отправитель совпадают; текст защищён и читается через штатный ключ\n";
