<?php

declare(strict_types=1);

// Проверка работает только в одноразовой тестовой базе после браузерного сценария.
if (getenv('E2E_ENABLE_MESSENGER') !== '1') {
    throw new RuntimeException('Проверка WebSocket разрешена только в испытательном окружении');
}

require_once dirname(__DIR__, 2) . '/core/Environment.php';
require_once dirname(__DIR__, 2) . '/modules/messenger/handlers/MessengerCrypto.php';

\Core\Environment::load(dirname(__DIR__, 2) . '/.env');
$marker = trim((string) file_get_contents('/tmp/notes-messenger-12-marker'));
$websocketMarker = trim((string) file_get_contents('/tmp/notes-messenger-12-websocket-marker'));
if ($marker === '' || $marker !== $websocketMarker || !str_starts_with($marker, 'Messenger-12-')) {
    throw new RuntimeException('Браузерная проверка WebSocket не завершилась');
}

$prefix = (string) (\Core\Environment::get('DBPREFIX') ?: '');
if ($prefix !== '' && preg_match('/^[A-Za-z0-9_]+$/D', $prefix) !== 1) {
    throw new RuntimeException('Недопустимый префикс таблиц');
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        \Core\Environment::get('DBHOST'),
        \Core\Environment::get('DBPORT') ?: '3306',
        \Core\Environment::get('DBNAME')),
    (string) \Core\Environment::get('DBUSER'),
    (string) \Core\Environment::get('DBPASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$rows = $pdo->query(
    'SELECT m.uid,m.message,m.message_type,m.dialog_id,u.username '
    . 'FROM ' . $prefix . 'messages m '
    . 'JOIN ' . $prefix . 'users u ON u.id=m.from_user_id'
)->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== 4) {
    throw new RuntimeException('После WebSocket ожидаются ровно 4 сообщения, найдено ' . count($rows));
}

$expected = [
    $marker . '-отправитель' => ['admin-lifecycle-user', 'text'],
    $marker . '-адресат' => ['admin-target-user', 'text'],
    $marker . '-вложение' => ['admin-lifecycle-user', 'file'],
    $marker . '-websocket' => ['admin-lifecycle-user', 'text'],
];
$dialogIds = [];
foreach ($rows as $row) {
    if (!str_starts_with((string) $row['message'], 'v2:')) {
        throw new RuntimeException('Обнаружена незашифрованная запись Messenger');
    }
    $body = \App\Helpers\MessengerCrypto::decrypt((string) $row['message'], (string) $row['uid']);
    $pair = $expected[$body] ?? null;
    if ($pair === null || $pair !== [(string) $row['username'], (string) $row['message_type']]) {
        throw new RuntimeException('Неожиданное сообщение или попытка записи посторонним');
    }
    unset($expected[$body]);
    $dialogIds[] = (int) $row['dialog_id'];
}
if ($expected !== [] || count(array_unique($dialogIds)) !== 1) {
    throw new RuntimeException('Состав сообщений или диалог после WebSocket не совпадает с ожидаемым');
}

fwrite(STDOUT, "[OK] MySQL: живой WebSocket записал сообщение ровно один раз; чужие действия не создали записей; история зашифрована\n");
