<?php

declare(strict_types=1);

// Сценарий запускается из data_integrity_12_contract.php на MySQL 8.4.
// Используются обе конфигурации DBPREFIX и тот же платформенный журнал.
require_once $root . '/modules/tasks/services/TaskPersonalQueue.php';
require_once $root . '/modules/tasks/services/TaskDayOrderService.php';

use Core\DatabaseManager;
use Core\MutationCommandStore;
use Modules\Tasks\TaskDayOrderService;

$now = new DateTimeImmutable('now');
$today = $now->format('Y-m-d');
$tomorrow = $now->modify('+1 day')->format('Y-m-d');
$insertDay = $pdo->prepare(
    'INSERT INTO ' . $pdo->namespace->name('tasks')
    . " (uid,user_id,title,status,priority,due_date,version,created_at,updated_at)
       VALUES (:uid,:user_id,:title,'pending','medium',:due_date,1,NOW(),NOW())"
);
$uids = [];
foreach (['08:00:00', '12:00:00', '18:00:00'] as $index => $time) {
    $uid = bin2hex(random_bytes(16));
    $uids[] = $uid;
    $insertDay->execute([
        ':uid' => $uid, ':user_id' => $userId,
        ':title' => 'Очередь дня ' . $index,
        ':due_date' => $today . ' ' . $time,
    ]);
}
$tomorrowUid = bin2hex(random_bytes(16));
$insertDay->execute([
    ':uid' => $tomorrowUid, ':user_id' => $userId,
    ':title' => 'Задача на завтра', ':due_date' => $tomorrow . ' 12:00:00',
]);

$queueDb = DatabaseManager::forConnection($pdo);
$queue = new TaskDayOrderService($queueDb, new MutationCommandStore());
integrityCheck($queue->version($userId, $today) === 1, 'новый день начинается с версии 1');

$first = $queue->move($userId, 'today', $uids[2], 'up', 1, 'day-order-first-0001', $now);
integrityCheck(($first['success'] ?? false) === true && ($first['version'] ?? 0) === 2,
    'первый перенос должен атомарно увеличить версию очереди');

$list = $queueDb->fetchAll(
    'SELECT t.uid FROM tasks t
     JOIN task_personal_day_positions p ON p.task_id=t.id AND p.user_id=t.user_id
     WHERE t.user_id=:user_id AND p.queue_date=:queue_date ORDER BY p.position ASC',
    [':user_id' => $userId, ':queue_date' => $today]
);
integrityCheck(array_column($list, 'uid') === [$uids[0], $uids[2], $uids[1]],
    'порядок после перестановки должен сохраняться в БД');

$again = $queue->move($userId, 'today', $uids[2], 'up', 1, 'day-order-first-0001', $now);
integrityCheck(($again['replayed'] ?? false) === true && ($again['version'] ?? 0) === 2,
    'повтор после потери ответа не меняет очередь');

$stale = $queue->move($userId, 'today', $uids[2], 'up', 1, 'day-order-stale-0001', $now);
integrityCheck(($stale['error'] ?? '') === 'version_conflict' && ($stale['version'] ?? 0) === 2,
    'устаревшая вкладка должна получить конфликт');

$reused = false;
try {
    $queue->move($userId, 'today', $uids[1], 'up', 1, 'day-order-first-0001', $now);
} catch (InvalidArgumentException) {
    $reused = true;
}
integrityCheck($reused, 'один идентификатор нельзя применять к разным перестановкам');

$wrongDay = $queue->move($userId, 'today', $tomorrowUid, 'up', 2, 'day-order-wrong-day-0001', $now);
integrityCheck(($wrongDay['error'] ?? '') === 'queue_changed',
    'перенос задачи из другого дня должен отклоняться');

$invalid = false;
try {
    $queue->move($userId, 'overdue', $uids[0], 'up', 2, 'day-order-overdue-0001', $now);
} catch (InvalidArgumentException) {
    $invalid = true;
}
integrityCheck($invalid, 'перестановки разрешены только для Сегодня и Завтра');

$edge = $queue->move($userId, 'today', $uids[0], 'up', 2, 'day-order-edge-0001', $now);
integrityCheck(($edge['unchanged'] ?? false) === true && ($edge['version'] ?? 0) === 2,
    'перенос крайней карточки не меняет версию');

$next = $queue->move($userId, 'today', $uids[1], 'up', 2, 'day-order-second-0001', $now);
integrityCheck(($next['success'] ?? false) === true && ($next['version'] ?? 0) === 3,
    'последующая актуальная команда должна примениться');

$taskVersions = $queueDb->fetchAll(
    'SELECT uid,version FROM tasks WHERE uid IN (:a,:b,:c)',
    [':a' => $uids[0], ':b' => $uids[1], ':c' => $uids[2]]
);
integrityCheck(count($taskVersions) === 3 && count(array_filter(
    $taskVersions, static fn (array $row): bool => (int) $row['version'] === 1
)) === 3, 'перестановка не должна увеличивать версии самих задач');

integrityCheck($queue->version($userId, $tomorrow) === 1,
    'очередь следующего дня должна иметь независимую версию');
echo "Контракт ручного порядка личной очереди Tasks выполнен\n";
