<?php

declare(strict_types=1);

// Проверка календарных границ и принадлежности личных задач.
// Реальное текущее время и рабочая БД не используются.
require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskPersonalQueue.php';

use Modules\Tasks\TaskPersonalQueue;

function queueCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$zone = new DateTimeZone('Europe/Stockholm');
$winter = new DateTimeImmutable('2026-12-31 23:30:00', $zone);
$boundaries = TaskPersonalQueue::boundaries($winter);
queueCheck($boundaries['today'] === '2026-12-31 00:00:00', 'Неверная граница сегодня');
queueCheck($boundaries['tomorrow'] === '2027-01-01 00:00:00', 'Переход через Новый год нарушен');
queueCheck($boundaries['after_tomorrow'] === '2027-01-02 00:00:00', 'Неверная граница послезавтра');

$beforeDst = new DateTimeImmutable('2026-03-28 23:30:00', $zone);
$dst = TaskPersonalQueue::boundaries($beforeDst);
queueCheck($dst['tomorrow'] === '2026-03-29 00:00:00', 'Пропала граница дня перехода на летнее время');
queueCheck($dst['after_tomorrow'] === '2026-03-30 00:00:00', 'Смена летнего времени сдвинула календарные сутки');

$summer = new DateTimeImmutable('2026-10-24 23:30:00', $zone);
$autumn = TaskPersonalQueue::boundaries($summer);
queueCheck($autumn['tomorrow'] === '2026-10-25 00:00:00'
    && $autumn['after_tomorrow'] === '2026-10-26 00:00:00',
    'Повторный осенний час сдвинул календарные границы');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('Необходимо расширение pdo_sqlite');
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE tasks (id INT,user_id INT,due_date TEXT,status TEXT,is_deleted INT,archived_at TEXT)');
$rows = [
    [1, 7, '2026-12-31 08:00:00', 'pending', 0, null],
    [2, 7, '2027-01-01 09:00:00', 'in_progress', 0, null],
    [3, 7, '2027-01-02 00:00:00', 'pending', 0, null],
    [4, 7, '2026-12-30 11:00:00', 'pending', 0, null],
    [5, 7, '2026-12-31 05:00:00', 'completed', 0, null],
    [6, 7, '2027-01-01 12:00:00', 'completed', 0, null],
    [7, 8, '2027-01-01 15:00:00', 'pending', 0, null],
    [8, 7, null, 'pending', 0, null],
    [9, 7, '2027-01-01 00:00:00', 'pending', 0, null],
    [10, 7, '2026-12-31 23:59:59', 'pending', 0, null],
    [11, 7, '2027-01-01 06:00:00', 'pending', 1, null],
    [12, 7, '2027-01-01 07:00:00', 'pending', 0, '2026-12-01 00:00:00'],
];
$insert = $db->prepare('INSERT INTO tasks VALUES (?,?,?,?,?,?)');
foreach ($rows as $row) {
    $insert->execute($row);
}

$expected = [
    'today' => [1, 10],
    'tomorrow' => [2, 9],
    'overdue' => [1, 4],
];
foreach ($expected as $filter => $ids) {
    $rule = TaskPersonalQueue::filter($filter, $winter);
    queueCheck($rule['sql'] !== '', 'Не найден фильтр ' . $filter);
    $query = $db->prepare('SELECT t.id FROM tasks t WHERE t.user_id = :user_id'
        . ' AND t.is_deleted = 0 AND t.archived_at IS NULL'
        . ' AND ' . $rule['sql'] . ' ORDER BY t.id');
    $query->execute([':user_id' => 7] + $rule['params']);
    $found = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    queueCheck($found === $ids, 'Нарушено личное представление: ' . $filter
        . ', результат ' . json_encode($found, JSON_UNESCAPED_UNICODE));
}
$week = TaskPersonalQueue::filter('week', $winter);
queueCheck(!str_contains($week['sql'], 'status'), 'Существующий недельный фильтр несовместимо изменился');
queueCheck(TaskPersonalQueue::filter('all', $winter)['sql'] === '',
    'Фильтр «Мои задачи» не должен сужать список по дате');

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/modules/tasks/controllers/TaskController.php');
$view = (string) file_get_contents($root . '/modules/tasks/views/index.php');
queueCheck(str_contains($controller, 'TaskPersonalQueue::filter')
    && str_contains($controller, "'tomorrow'"), 'Контроллер не использует единый календарный контракт');
queueCheck(str_contains($view, "'tomorrow' => 'Завтра'")
    && str_contains($view, 'aria-current="page"'), 'Личные представления недоступны в интерфейсе');
echo "Контракт личной очереди Tasks и календарных границ выполнен\n";
