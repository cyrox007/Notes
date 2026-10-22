<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/modules/tasks/services/TaskReadApiService.php';

use Core\DatabaseManager;
use Modules\Tasks\TaskReadApiService;

function taskApiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('API списка Tasks: ' . $message);
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('CREATE TABLE tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uid TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    status TEXT NOT NULL DEFAULT "pending",
    priority TEXT NOT NULL DEFAULT "medium",
    due_date TEXT,
    completed_at TEXT,
    archived_at TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    is_deleted INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)');
$insert = $pdo->prepare('INSERT INTO tasks
    (uid,user_id,title,created_at,updated_at,is_deleted)
    VALUES (?,?,?,?,?,?)');
foreach ([
    ['a', 11, 'Первая', '2026-10-21 10:00:00', 0],
    ['b', 11, 'Вторая', '2026-10-21 10:00:00', 0],
    ['c', 12, 'Чужая задача', '2026-10-21 11:00:00', 0],
    ['d', 11, 'Удалённая', '2026-10-21 12:00:00', 1],
    ['e', 11, 'Третья', '2026-10-20 10:00:00', 0],
] as [$uid,$userId,$title,$created,$deleted]) {
    $insert->execute([str_repeat($uid, 32), $userId, $title, $created, $created, $deleted]);
}

$service = new TaskReadApiService(DatabaseManager::forConnection($pdo));
$page = $service->listOwned(11, 1);
taskApiAssert(count($page['items']) === 1 && $page['items'][0]['title'] === 'Вторая',
    'первая страница отсортирована неверно');
taskApiAssert($page['has_more'] === true && is_string($page['next_cursor']),
    'отсутствует курсор продолжения');
taskApiAssert(!array_key_exists('id', $page['items'][0]), 'в ответ попал внутренний ID');

$insert->execute([str_repeat('f', 32), 11, 'Новая', '2026-10-22 10:00:00', '2026-10-22 10:00:00', 0]);
$second = $service->listOwned(11, 1, $page['next_cursor']);
taskApiAssert($second['items'][0]['title'] === 'Первая',
    'новая запись изменила продолжение курсора');
$third = $service->listOwned(11, 1, $second['next_cursor']);
taskApiAssert($third['items'][0]['title'] === 'Третья' && !$third['has_more']
    && $third['next_cursor'] === null, 'последняя страница неверна');

$rejected = 0;
foreach ([[12, 1, $page['next_cursor']], [11, 101, null], [11, 0, null],
          [11, 1, 'bad!cursor']] as [$user,$limit,$cursor]) {
    try {
        $service->listOwned($user, $limit, $cursor);
    } catch (InvalidArgumentException) {
        ++$rejected;
    }
}
taskApiAssert($rejected === 4, 'чужие курсоры или некорректный лимит приняты');

$other = $service->listOwned(12, 100);
taskApiAssert(count($other['items']) === 1 && $other['items'][0]['title'] === 'Чужая задача',
    'нарушена изоляция владельцев');
echo "Tasks API v1: курсор, предел выдачи, владельцы, удаления и новые записи проверены\n";
