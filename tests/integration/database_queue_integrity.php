<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

require dirname(__DIR__, 2) . '/core/config.php';
require dirname(__DIR__, 2) . '/core/DatabaseManager.php';

use Core\DatabaseManager;
use RuntimeException;
use Throwable;

$db = DatabaseManager::getInstance();
$db->execute('DROP TABLE IF EXISTS queue_integrity_test');
$db->execute(
    'CREATE TABLE queue_integrity_test ('
    . 'id INT AUTO_INCREMENT PRIMARY KEY,'
    . 'code VARCHAR(50) NOT NULL UNIQUE'
    . ') ENGINE=InnoDB'
);

$db->queueInsert(['code' => 'duplicate'], 'queue_integrity_test');
$db->queueInsert(['code' => 'duplicate'], 'queue_integrity_test');

$thrown = false;
try {
    $db->commit();
} catch (Throwable) {
    $thrown = true;
}

if (!$thrown) {
    throw new RuntimeException('Queued transaction failure was swallowed');
}

$count = (int) $db->fetchValue('SELECT COUNT(*) FROM queue_integrity_test');
if ($count !== 0) {
    throw new RuntimeException('Failed queued transaction was not fully rolled back');
}

$result = $db
    ->queueInsert(['code' => 'ok'], 'queue_integrity_test')
    ->commit();

if (!is_array($result) || (int) ($result[0]['last_insert_id'] ?? 0) <= 0) {
    throw new RuntimeException('Successful queued transaction contract regressed');
}

$count = (int) $db->fetchValue('SELECT COUNT(*) FROM queue_integrity_test');
if ($count !== 1) {
    throw new RuntimeException('Successful queued transaction was not persisted');
}

echo "Queued transaction rollback/error contract: OK\n";
