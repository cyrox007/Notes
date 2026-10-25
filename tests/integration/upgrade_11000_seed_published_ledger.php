<?php

declare(strict_types=1);

/**
 * Стендовое восстановление журнала из опубликованного набора 1.1.0.
 * Без реального SQL-обновления: следующий этап выполняет штатный мигратор.
 */
$root = dirname(__DIR__, 2);
require_once $root . '/core/UpdateDatabaseMigrator.php';

use Core\PrefixMysqli;
use Core\MigrationManifest;
use Core\MigrationBaseline;

$database = (string) (getenv('DBNAME') ?: '');
if ($database === '' || !str_contains(strtolower($database), 'test')) {
    throw new RuntimeException('Журнал разрешено подготавливать только в тестовой базе');
}

$manifest = new MigrationManifest($root);
$baseline = MigrationBaseline::appliedNames($manifest->names(), 11000);
if (count($baseline) !== 36) {
    throw new RuntimeException('Число исторических миграций отличается от опубликованной 1.1.0');
}

$db = new PrefixMysqli(
    (string) (getenv('DBHOST') ?: '127.0.0.1'),
    (string) (getenv('DBUSER') ?: ''),
    (string) (getenv('DBPASS') ?: ''),
    $database,
    (int) (getenv('DBPORT') ?: 3306)
);
$db->set_charset('utf8mb4');

try {
    $db->query(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(190) NOT NULL,
            checksum CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_schema_migrations_name (migration)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $already = $db->query('SELECT COUNT(*) AS total FROM schema_migrations')->fetch_assoc();
    if ((int) ($already['total'] ?? -1) !== 0) {
        throw new RuntimeException('Стендовый исторический журнал уже заполнен');
    }

    $statement = $db->prepare('INSERT INTO schema_migrations (migration,checksum) VALUES (?,?)');
    foreach ($baseline as $filename) {
        $checksum = hash('sha256', $manifest->readMigration($filename));
        $statement->bind_param('ss', $filename, $checksum);
        $statement->execute();
    }
    $statement->close();
    $count = $db->query('SELECT COUNT(*) AS total FROM schema_migrations')->fetch_assoc();
    if ((int) ($count['total'] ?? -1) !== count($baseline)) {
        throw new RuntimeException('Не удалось создать полный журнал исходной версии');
    }
} finally {
    $db->close();
}

echo 'Исторический журнал 1.1.0 подготовлен: ' . count($baseline) . " записей\n";
