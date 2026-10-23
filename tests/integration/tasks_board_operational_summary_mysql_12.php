<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/DatabaseManager.php';
require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskBoardOperationalSummary.php';

use Core\DatabaseManager;
use Modules\Tasks\TaskBoardOperationalSummary;

function summaryCheck(bool $ok, string $reason): void
{
    if (!$ok) {
        throw new RuntimeException('Сводка досок: ' . $reason);
    }
}

$name = (string) (getenv('TEST_DB_NAME') ?: '');
summaryCheck(str_contains(strtolower($name), 'test'), 'нужна изолированная тестовая база');

$db = DatabaseManager::forConnection(new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('TEST_DB_HOST') ?: '127.0.0.1',
        getenv('TEST_DB_PORT') ?: '3306',
        $name),
    getenv('TEST_DB_USER') ?: 'workspace',
    getenv('TEST_DB_PASS') ?: 'workspace',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
));

foreach ([
    "CREATE TABLE users(id INT PRIMARY KEY,is_active TINYINT DEFAULT 1,account_status VARCHAR(20) DEFAULT 'active')",
    "CREATE TABLE task_boards(id INT PRIMARY KEY,uid CHAR(32),owner_user_id INT,is_archived TINYINT DEFAULT 0)",
    "CREATE TABLE task_board_members(board_id INT,user_id INT,role VARCHAR(20),PRIMARY KEY(board_id,user_id))",
    "CREATE TABLE task_board_items(id INT PRIMARY KEY,board_id INT,status VARCHAR(24),
        due_date DATETIME NULL,updated_at DATETIME,completed_at DATETIME NULL,
        is_deleted TINYINT DEFAULT 0,archived_at DATETIME NULL)",
    "CREATE TABLE task_board_assignees(task_id INT,user_id INT,PRIMARY KEY(task_id,user_id))",
] as $sql) {
    $db->execute($sql);
}

try {
    $db->execute("INSERT INTO users VALUES(10,1,'active'),(11,1,'active'),(12,1,'active'),(13,1,'active'),(14,0,'active')");
    $db->execute("INSERT INTO task_boards(id,uid,owner_user_id,is_archived) VALUES
        (1,'" . str_repeat('a', 32) . "',10,0),
        (2,'" . str_repeat('b', 32) . "',13,0),
        (3,'" . str_repeat('c', 32) . "',10,1)");
    $db->execute("INSERT INTO task_board_members VALUES (1,11,'manager'),(1,12,'member'),(1,14,'manager')");
    $db->execute("INSERT INTO task_board_items
        (id,board_id,status,due_date,updated_at,completed_at,is_deleted,archived_at) VALUES
        (1,1,'pending','2026-10-20 11:00:00','2026-10-01 10:00:00',NULL,0,NULL),
        (2,1,'in_progress','2026-10-27 11:00:00','2026-10-22 10:00:00',NULL,0,NULL),
        (3,1,'completed','2026-10-18 11:00:00','2026-10-21 10:00:00','2026-10-20 10:00:00',0,NULL),
        (4,1,'completed','2026-10-01 11:00:00','2026-10-03 10:00:00','2026-10-03 10:00:00',0,NULL),
        (5,1,'cancelled',NULL,'2026-10-01 10:00:00',NULL,0,NULL),
        (6,1,'pending','2026-10-01 10:00:00','2026-10-01 10:00:00',NULL,0,'2026-10-21 10:00:00'),
        (7,1,'pending','2026-10-01 10:00:00','2026-10-01 10:00:00',NULL,1,NULL),
        (8,1,'review','2026-10-29 11:00:00','2026-10-01 10:00:00',NULL,0,NULL),
        (9,2,'pending','2026-10-01 11:00:00','2026-10-01 10:00:00',NULL,0,NULL)");
    $db->execute('INSERT INTO task_board_assignees VALUES (2,11),(8,11)');
    $service = new TaskBoardOperationalSummary($db);
    $now = new DateTimeImmutable('2026-10-23 10:00:00+00:00');

    foreach ([10,11] as $userId) {
        $s = $service->forBoard($userId, str_repeat('a',32), $now);
        summaryCheck($s['total'] === 6 && $s['active'] === 3
            && $s['overdue'] === 1 && $s['stagnant'] === 2
            && $s['completed_7d'] === 1 && $s['unassigned'] === 1,
            'неверные агрегаты владельца или руководителя');
        summaryCheck(!isset($s['title']) && !isset($s['user_id']),
            'в сводку просочились персональные данные');
    }

    foreach ([12,13,14] as $userId) {
        try {
            $service->forBoard($userId, str_repeat('a',32), $now);
            throw new RuntimeException('Сводка досок: нет запрета для пользователя ' . $userId);
        } catch (DomainException $error) {
            summaryCheck($error->getCode() === 403, 'неверный отказ в доступе');
        }
    }

    try {
        $service->forBoard(10, str_repeat('c', 32), $now);
        throw new RuntimeException('Сводка досок: доступна архивная доска');
    } catch (DomainException $error) {
        summaryCheck($error->getCode() === 403, 'архивная доска не скрыта');
    }

    echo "Сводка досок: точность агрегатов и права доступа проверены\n";
} finally {
    foreach (['task_board_assignees','task_board_items','task_board_members','task_boards','users'] as $table) {
        $db->execute('DROP TABLE IF EXISTS ' . $table);
    }
}
