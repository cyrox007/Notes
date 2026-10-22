<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$name = (string) (getenv('TEST_DB_NAME') ?: '');
if ($name === '' || !str_contains(strtolower($name), 'test')) {
    throw new RuntimeException('Нужна отдельная тестовая БД');
}

require_once $root . '/core/PrefixMysqli.php';

function migrationCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Миграция Tasks 1.2: ' . $message);
    }
}

function executeMigration(\Core\PrefixMysqli $db, string $sql): void
{
    // В этой миграции все выражения заканчиваются точкой с запятой,
    // и внутренние строки не содержат разделителей команд.
    foreach (explode(';', preg_replace('/^\s*--[^\n]*\n/m', '', $sql) ?? '') as $statement) {
        if (trim($statement) === '') {
            continue;
        }
        $result = $db->schemaQuery(trim($statement));
        if ($result instanceof \mysqli_result) {
            $result->free();
        }
    }
}

$sql = file_get_contents($root . '/database/migrations/20261017_tasks_entity_versions.sql');
migrationCheck(is_string($sql), 'не найдена миграция версий');

$initialPrefix = getenv('DBPREFIX');
$host = (string) (getenv('TEST_DB_HOST') ?: '127.0.0.1');
$port = (int) (getenv('TEST_DB_PORT') ?: 3306);
$user = (string) (getenv('TEST_DB_USER') ?: 'workspace');
$pass = (string) (getenv('TEST_DB_PASS') ?: 'workspace');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    foreach (['', 'nt12_'] as $prefix) {
        putenv('DBPREFIX=' . $prefix);
        $db = new \Core\PrefixMysqli($host, $user, $pass, $name, $port);
        $db->set_charset('utf8mb4');
        try {
            $db->schemaQuery('CREATE TABLE IF NOT EXISTS tasks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                uid CHAR(32) NOT NULL,
                deleted_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB');
            $db->schemaQuery('CREATE TABLE IF NOT EXISTS task_board_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                deleted_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB');

            $db->query("INSERT INTO tasks(uid) VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')");
            $db->query('INSERT INTO task_board_items() VALUES ()');

            executeMigration($db, $sql);
            executeMigration($db, $sql); // повтор миграции не дублирует столбцы

            foreach (['tasks', 'task_board_items'] as $table) {
                $physical = $db->namespace->name($table);
                $stmt = $db->prepare('SELECT COUNT(*) AS total
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
                $column = 'version';
                $stmt->bind_param('ss', $physical, $column);
                $stmt->execute();
                $found = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                migrationCheck((int) ($found['total'] ?? 0) === 1,
                    'поле версии отсутствует или создано дважды: ' . $physical);

                $result = $db->query('SELECT version FROM ' . $table . ' LIMIT 1');
                $row = $result->fetch_assoc();
                $result->free();
                migrationCheck((int) ($row['version'] ?? 0) === 1,
                    'исходная строка потеряна или получила неверную версию: ' . $physical);
            }
            echo 'Префикс ' . ($prefix === '' ? '(пустой)' : $prefix)
                . ': существующие задачи сохранены, версии созданы один раз' . PHP_EOL;
        } finally {
            $db->schemaQuery('DROP TABLE IF EXISTS task_board_items');
            $db->schemaQuery('DROP TABLE IF EXISTS tasks');
            $db->close();
        }
    }
} finally {
    putenv($initialPrefix === false ? 'DBPREFIX' : 'DBPREFIX=' . $initialPrefix);
}
