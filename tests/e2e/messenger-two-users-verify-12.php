<?php

declare(strict_types=1);

// Запускать исключительно в одноразовом стенде GitHub Actions.
if (getenv('E2E_ENABLE_MESSENGER') !== '1') {
    throw new RuntimeException('Проверка хранения Messenger разрешена только в испытательном окружении');
}

require_once dirname(__DIR__, 2) . '/core/Environment.php';
require_once dirname(__DIR__, 2) . '/modules/messenger/handlers/MessengerCrypto.php';

\Core\Environment::load(dirname(__DIR__, 2) . '/.env');
$marker = trim((string) file_get_contents('/tmp/notes-messenger-12-marker'));
if (!str_starts_with($marker, 'Messenger-12-')) {
    throw new RuntimeException('Маркер браузерной отправки отсутствует');
}

$prefix = (string) (\Core\Environment::get('DBPREFIX') ?: '');
if ($prefix !== '' && preg_match('/^[A-Za-z0-9_]+$/D', $prefix) !== 1) {
    throw new RuntimeException('Некорректный префикс БД');
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
    'SELECT m.id,m.uid,m.message,m.message_type,m.from_user_id,m.dialog_id,u.username '
    . 'FROM ' . $prefix . 'messages m '
    . 'JOIN ' . $prefix . 'users u ON u.id=m.from_user_id'
)->fetchAll(PDO::FETCH_ASSOC);

if (count($rows) !== 3) {
    throw new RuntimeException('Ожидались два текста и вложение, получено записей: ' . count($rows));
}

$senders = [];
$dialogIds = [];
$attachmentId = null;
$verifiedTexts = [];
foreach ($rows as $row) {
    if (!str_starts_with((string) $row['message'], 'v2:')) {
        throw new RuntimeException('Сообщение не зашифровано в БД');
    }
    $text = \App\Helpers\MessengerCrypto::decrypt((string) $row['message'], (string) $row['uid']);
    $expectedType = $text === $marker . '-вложение' ? 'file' : 'text';
    if (!in_array($text, [$marker . '-отправитель', $marker . '-адресат', $marker . '-вложение'], true)
        || (string) $row['message_type'] !== $expectedType) {
        throw new RuntimeException('Содержимое или тип сохранённого сообщения отличается от отправленного');
    }
    $verifiedTexts[] = $text;
    if ($expectedType === 'file') {
        $attachmentId = (int) $row['id'];
    }
    $senders[] = (string) $row['username'];
    $dialogIds[] = (int) $row['dialog_id'];
}

sort($senders);
$expected = ['admin-lifecycle-user', 'admin-lifecycle-user', 'admin-target-user'];
sort($expected);
if ($senders !== $expected || count(array_unique($dialogIds)) !== 1
    || count(array_unique($verifiedTexts)) !== 3 || $attachmentId === null) {
    throw new RuntimeException('Состав сообщений, отправители или принадлежность диалогу некорректны');
}

$attachment = $pdo->prepare(
    'SELECT COUNT(*) FROM ' . $prefix . 'messenger_attachments WHERE message_id = :message_id'
);
$attachment->execute([':message_id' => $attachmentId]);
if ((int) $attachment->fetchColumn() !== 1) {
    throw new RuntimeException('Запись вложения не связана с сохранённым сообщением');
}

fwrite(STDOUT, "[OK] MySQL: два текста и вложение, два отправителя, единый диалог, шифрование и привязка файла подтверждены\n");
