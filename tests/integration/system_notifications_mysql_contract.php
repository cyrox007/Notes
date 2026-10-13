<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/CliRuntime.php';
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/core/Config.php';
require_once $root . '/app/services/notifications/SystemNotificationRuntime.php';

use App\Services\Notifications\SystemNotificationRuntime;
use Core\DatabaseManager;
use Core\SystemEventOutbox;

function systemNotificationAssert(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}

$db = DatabaseManager::getInstance();
// Run only in a disposable database: this mirrors the notification CI fixtures.
foreach (['core_identity_schema.sql', 'access_control_schema.sql', 'migrations/20261010_notifications_foundation.sql'] as $schema) {
    $connection = new mysqli((string) getenv('DBHOST'), (string) getenv('DBUSER'), (string) getenv('DBPASS'), (string) getenv('DBNAME'), (int) (getenv('DBPORT') ?: 3306));
    $connection->set_charset('utf8mb4');
    $connection->multi_query((string) file_get_contents($root . '/database/' . $schema));
    do { if ($result = $connection->store_result()) $result->free(); } while ($connection->more_results() && $connection->next_result());
    $connection->close();
}
$ids = [];
foreach (['admin' => ['admin', 'active'], 'superadmin' => ['superadmin', 'active'], 'regular' => ['user', 'active'], 'blocked' => ['admin', 'blocked']] as $name => [$role, $status]) {
    $db->execute("INSERT INTO users(uid,username,email,password_hash,firstname,lastname,role,is_active,account_status) VALUES(UUID(),:name,:email,'fixture','Test','User',888,1,:status)", [':name' => 'system-' . $name, ':email' => $name . '@example.test', ':status' => $status]);
    $id = (int) $db->fetchValue('SELECT id FROM users WHERE username=:name', [':name' => 'system-' . $name]);
    $ids[$name] = $id;
    $db->execute('DELETE FROM user_roles WHERE user_id=:id', [':id' => $id]);
    $db->execute('INSERT INTO user_roles(user_id,role_id) SELECT :id,id FROM roles WHERE code=:role', [':id' => $id, ':role' => $role]);
}
$path = sys_get_temp_dir() . '/system-notifications-' . bin2hex(random_bytes(8));
$outbox = new SystemEventOutbox($path);
$runtime = new SystemNotificationRuntime($outbox, $db);
try {
    foreach (SystemEventOutbox::NAMES as $name) $outbox->enqueue($name, 'fixture-001');
    systemNotificationAssert($runtime->run(3) === 3 && $runtime->run(3) === 2, 'System batch did not resume');
    systemNotificationAssert((int) $db->fetchValue('SELECT COUNT(*) FROM notification_events') === 5, 'Missing operational event');
    foreach ($ids as $name => $id) {
        $count = (int) $db->fetchValue('SELECT COUNT(*) FROM notifications WHERE recipient_user_id=:id', [':id' => $id]);
        systemNotificationAssert($count === (in_array($name, ['admin', 'superadmin'], true) ? 5 : 0), 'RBAC/account recipient boundary failed: ' . $name);
    }
    $outbox->enqueue('update.installed', 'fixture-001');
    $runtime->run();
    systemNotificationAssert((int) $db->fetchValue('SELECT COUNT(*) FROM notifications') === 10, 'Replay duplicated notifications');
    $db->execute('DELETE FROM user_roles WHERE user_id IN (:admin,:super)', [':admin' => $ids['admin'], ':super' => $ids['superadmin']]);
    $outbox->enqueue('update.available', 'fixture-002');
    systemNotificationAssert($runtime->run() === 0 && count(glob($path . '/*.json') ?: []) === 1, 'Event must survive missing eligible operators');
    echo "[OK] System notifications: five sources, RBAC, blocked recipients, replay and deferred delivery\n";
} finally {
    foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) unlink($entry->getPathname());
    rmdir($path);
    DatabaseManager::resetInstance();
}
