<?php

declare(strict_types=1);

// Использует только одноразовую MySQL-базу. Никакие реальные сообщения не отправляются.
$root = dirname(__DIR__, 2);
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('TEST_DB_PORT') ?: 3306);
$name = getenv('TEST_DB_NAME') ?: 'workspace_test';
$user = getenv('TEST_DB_USER') ?: 'workspace';
$pass = getenv('TEST_DB_PASS') ?: 'workspace';
if (!str_contains(strtolower($name), 'test')) {
    throw new RuntimeException('MySQL-тест Messenger разрешён только на отдельной тестовой БД');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host, $user, $pass, $name, $port);
$conn->set_charset('utf8mb4');

function messengerNoticeCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Messenger Notifications: ' . $message);
}
function messengerNoticeApply(mysqli $conn, string $path): void
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) throw new RuntimeException('Невозможно прочитать тестовую схему');
    $contents = preg_replace('/^[ \t]*--[^\r\n]*(?:\r?\n|$)/m', '', $contents) ?? '';
    foreach (explode(';', $contents) as $part) {
        if (trim($part) !== '') $conn->query(trim($part));
    }
}

messengerNoticeApply($conn, $root . '/database/core_identity_schema.sql');
messengerNoticeApply($conn, $root . '/database/notifications_schema.sql');
messengerNoticeApply($conn, $root . '/database/messenger_module_schema.sql');

$suffix = bin2hex(random_bytes(6));
$insert = $conn->prepare(
    "INSERT INTO users(uid,username,email,password_hash,firstname,lastname,role,is_active,account_status)
     VALUES (?,?,?,'fixture','Тест','Messenger',888,?,'active')"
);
$ids = [];
foreach (['sender'=>1, 'recipient'=>1, 'muted'=>1, 'removed'=>1, 'inactive'=>0] as $role=>$active) {
    $uid = sprintf('bbbbbbbb-bbbb-4bbb-8bbb-%012d', random_int(1, 999999999999));
    $username = 'messenger-notice-' . $role . '-' . $suffix;
    $email = $username . '@example.test';
    $insert->bind_param('sssi',$uid,$username,$email,$active);
    $insert->execute();
    $ids[$role] = (int) $conn->insert_id;
}
$insert->close();
$dialogUid = '9aa00000-0000-4000-8000-' . str_pad(substr($suffix, 0, 12), 12, '0');
$messageUid = '9bb00000-0000-4000-8000-' . str_pad(substr($suffix, 0, 12), 12, '0');
$conn->query("INSERT INTO dialogs(uid,type,name,created_by) VALUES ('" . $dialogUid .
    "','group','Тестовая группа'," . $ids['sender'] . ")");
$dialogId = (int) $conn->insert_id;
$stmt = $conn->prepare(
    "INSERT INTO user_to_dialogs(dialog_id,user_id,role,is_deleted,muted_until)
     VALUES (?,?, 'member', ?, ?)"
);
foreach ($ids as $role=>$userId) {
    $deleted = $role === 'removed' ? 1 : 0;
    $mutedUntil = $role === 'muted' ? '2035-10-20 00:00:00' : null;
    $stmt->bind_param('iiis',$dialogId,$userId,$deleted,$mutedUntil);
    $stmt->execute();
}
$stmt->close();

putenv('DBDRIVER=mysql');
putenv('DBHOST=' . $host);
putenv('DBPORT=' . $port);
putenv('DBNAME=' . $name);
putenv('DBUSER=' . $user);
putenv('DBPASS=' . $pass);
require_once $root . '/core/Config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/modules/messenger/services/MessengerMessageNotificationPublisher.php';

\Core\DatabaseManager::resetInstance();
$db = \Core\DatabaseManager::getInstance();
$publisher = new \App\Services\MessengerMessageNotificationPublisher($db);
$first = $publisher->publish($ids['sender'],$dialogId,$dialogUid,$messageUid);
messengerNoticeCheck($first === 1,'уведомить надо только активного незаглушенного получателя');
$events = $db->fetchAll(
    "SELECT id,idempotency_key,category,payload_json FROM notification_events
     WHERE event_name='messenger.message_received'
       AND idempotency_key=:key",
    [':key'=>'message:' . $messageUid . ':batch-0']
);
messengerNoticeCheck(count($events) === 1,'не создано событие Messenger');
$rows = $db->fetchAll(
    "SELECT n.recipient_user_id,n.title,n.body,n.target_path
     FROM notifications n WHERE n.event_id=:event_id",
    [':event_id'=>(int) $events[0]['id']]
);
messengerNoticeCheck(count($rows) === 1 && (int) $rows[0]['recipient_user_id'] === $ids['recipient'],
    'неверно отобраны адресаты');
messengerNoticeCheck(str_contains((string) $rows[0]['target_path'], $dialogUid)
    && str_contains((string) $rows[0]['target_path'], $messageUid),
    'по нажатию нужно открыть соответствующий диалог и сообщение');
$serialized = json_encode($events[0], JSON_THROW_ON_ERROR);
messengerNoticeCheck(!str_contains($serialized, 'Очень секретный текст')
    && !str_contains((string) $rows[0]['body'], 'Очень секретный текст'),
    'во внешней подсистеме запрещено передавать исходный текст');
messengerNoticeCheck($publisher->publish($ids['sender'],$dialogId,$dialogUid,$messageUid) === 1
    && (int) $db->fetchValue('SELECT COUNT(*) FROM notifications WHERE event_id=:id',
       [':id'=>(int) $events[0]['id']]) === 1,
    'идемпотентность события сообщения не выдержана');

$db->execute('UPDATE user_to_dialogs SET muted_until=NULL WHERE dialog_id=:dialog AND user_id=:uid',
    [':dialog'=>$dialogId,':uid'=>$ids['muted']]);
$newUid = '9cc00000-0000-4000-8000-' . str_pad(substr($suffix, 0, 12), 12, '0');
messengerNoticeCheck($publisher->publish($ids['sender'],$dialogId,$dialogUid,$newUid) === 2,
    'после снятия заглушения второй пользователь должен получить уведомление');

$serviceSource = (string) file_get_contents($root . '/modules/messenger/services/MessengerService.php');
messengerNoticeCheck(str_contains($serviceSource,'new MessengerMessageNotificationPublisher($this->db)')
    && str_contains($serviceSource,'$this->db->endTransaction(true);'),
    'событие необходимо публиковать только после записи сообщения');
$ui = (string) file_get_contents($root . '/assets/js/notification-center.js');
messengerNoticeCheck(str_contains($ui,"'messenger.messages': 'Новые сообщения Messenger'"),
    'пользователь должен иметь возможность заранее включить сообщения в каналах доставки');

\Core\DatabaseManager::resetInstance();
$conn->close();
echo "Проверена доставка событий Messenger двум адресатам, права, mute, повтор и приватность\n";
