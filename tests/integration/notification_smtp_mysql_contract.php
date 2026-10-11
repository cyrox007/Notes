<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$dbHost = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$dbPort = (int) (getenv('TEST_DB_PORT') ?: 3306);
$dbName = getenv('TEST_DB_NAME') ?: 'workspace_test';
$dbUser = getenv('TEST_DB_USER') ?: 'workspace';
$dbPass = getenv('TEST_DB_PASS') ?: 'workspace';

putenv('DBDRIVER=mysql');
putenv('DBHOST=' . $dbHost);
putenv('DBPORT=' . $dbPort);
putenv('DBNAME=' . $dbName);
putenv('DBUSER=' . $dbUser);
putenv('DBPASS=' . $dbPass);

require_once $root . '/core/Config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/app/services/notifications/NotificationEmailRecipientResolver.php';
require_once $root . '/app/services/notifications/DatabaseNotificationEmailRecipientResolver.php';

function notificationSmtpMysqlAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

\Core\DatabaseManager::resetInstance();
$db = \Core\DatabaseManager::getInstance();
$resolver = new \App\Services\Notifications\DatabaseNotificationEmailRecipientResolver($db);

$active = $db->fetchOne("SELECT id, email FROM users WHERE username = 'active-user' LIMIT 1");
$inactive = $db->fetchOne("SELECT id FROM users WHERE username = 'inactive-user' LIMIT 1");
$deleting = $db->fetchOne("SELECT id FROM users WHERE username = 'deleting-user' LIMIT 1");

notificationSmtpMysqlAssert(is_array($active), 'Базовый MySQL-контракт должен оставить активного тестового пользователя');
notificationSmtpMysqlAssert(is_array($inactive), 'Базовый MySQL-контракт должен оставить отключённого тестового пользователя');
notificationSmtpMysqlAssert(is_array($deleting), 'Базовый MySQL-контракт должен оставить удаляемого тестового пользователя');

$activeId = (int) $active['id'];
notificationSmtpMysqlAssert(
    $resolver->emailForUser($activeId) === (string) $active['email'],
    'SMTP resolver должен вернуть актуальный адрес активного пользователя'
);
notificationSmtpMysqlAssert(
    $resolver->emailForUser((int) $inactive['id']) === null,
    'SMTP resolver не должен возвращать адрес отключённого пользователя'
);
notificationSmtpMysqlAssert(
    $resolver->emailForUser((int) $deleting['id']) === null,
    'SMTP resolver не должен возвращать адрес пользователя с заявкой на удаление'
);

$db->execute(
    'UPDATE users SET email = :email WHERE id = :id',
    [':email' => 'changed@example.test', ':id' => $activeId]
);
notificationSmtpMysqlAssert(
    $resolver->emailForUser($activeId) === 'changed@example.test',
    'SMTP resolver должен читать адрес непосредственно перед отправкой, а не кэшировать его'
);

\Core\DatabaseManager::resetInstance();
fwrite(STDOUT, "[OK] MySQL-контракт адреса SMTP уведомлений 1.1.0\n");
