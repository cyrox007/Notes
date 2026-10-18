<?php

declare(strict_types=1);

// Этот сценарий запускается после создания изолированной БД в data_integrity_12_contract.php.
require_once $root . '/modules/tasks/services/TaskBoardVersionMutationService.php';

use App\Services\Tasks\TaskBoardVersionMutationService;
use Core\MutationCommandStore;

$users = [];
foreach (['board-member', 'board-viewer', 'board-outsider'] as $name) {
    $installer->createAdminUser($pdo, $name, $name . '@example.test', 'integrity-board-test-password', 'Test', 'Board');
    $users[$name] = (int) $pdo->query(
        "SELECT id FROM users WHERE username='" . $name . "' LIMIT 1"
    )->fetchColumn();
    integrityCheck($users[$name] > 0, 'тестовый участник не создан');
}
$member = $users['board-member'];
$viewer = $users['board-viewer'];
$outsider = $users['board-outsider'];

$boardUid = bin2hex(random_bytes(16));
$taskUid = bin2hex(random_bytes(16));
$boardInsert = $pdo->prepare(
    "INSERT INTO task_boards (uid,owner_user_id,name,audience,is_archived)
     VALUES (:uid,:owner,'Контракт общей доски','members',0)"
);
$boardInsert->execute([':uid' => $boardUid, ':owner' => $userId]);
$boardId = (int) $pdo->lastInsertId();
$membership = $pdo->prepare(
    'INSERT INTO task_board_members (board_id,user_id,role) VALUES (:board,:actor,:role)'
);
$membership->execute([':board' => $boardId, ':actor' => $member, ':role' => 'member']);
$membership->execute([':board' => $boardId, ':actor' => $viewer, ':role' => 'viewer']);

$item = $pdo->prepare(
    "INSERT INTO task_board_items
      (uid,board_id,creator_user_id,title,status,priority,version)
     VALUES (:uid,:board,:creator,'Исходная общая задача','pending','medium',1)"
);
$item->execute([':uid' => $taskUid, ':board' => $boardId, ':creator' => $userId]);

$service = new TaskBoardVersionMutationService(
    $pdo, new MutationCommandStore(), static fn (int $actorId): bool => true
);
$first = $service->mutate(
    $userId, $taskUid, 1, 'board-owner-command-1', 'board.task.status', ['status' => 'in_progress']
);
integrityCheck(($first['success'] ?? false) === true && $first['version'] === 2,
    'первый участник меняет статус только один раз');

$stale = $service->mutate(
    $member, $taskUid, 1, 'board-member-command-1', 'board.task.status', ['status' => 'completed']
);
integrityCheck(($stale['error'] ?? '') === 'version_conflict' && $stale['current_version'] === 2,
    'другой участник получает конфликт устаревшей версии');
integrityCheck(($stale['current']['status'] ?? '') === 'in_progress',
    'конфликт возвращает актуальный статус');

$repeated = $service->mutate(
    $userId, $taskUid, 1, 'board-owner-command-1', 'board.task.status', ['status' => 'in_progress']
);
integrityCheck(($repeated['replayed'] ?? false) === true && $repeated['version'] === 2,
    'повтор потерянного ответа не меняет версию');
$staleRepeated = $service->mutate(
    $member, $taskUid, 1, 'board-member-command-1', 'board.task.status', ['status' => 'completed']
);
integrityCheck(($staleRepeated['replayed'] ?? false) === true,
    'повтор конфликта возвращает сохранённый результат');

$reused = false;
try {
    $service->mutate(
        $userId, $taskUid, 1, 'board-owner-command-1', 'board.task.status', ['status' => 'completed']
    );
} catch (\InvalidArgumentException) {
    $reused = true;
}
integrityCheck($reused, 'другая команда не может использовать прежний command_id');

$viewerDenied = false;
try {
    $service->mutate(
        $viewer, $taskUid, 2, 'board-viewer-command-1', 'board.task.status', ['status' => 'review']
    );
} catch (\DomainException $error) {
    $viewerDenied = $error->getCode() === 403;
}
integrityCheck($viewerDenied, 'пользователь только для чтения не изменяет общую задачу');

$outsiderDenied = false;
try {
    $service->mutate(
        $outsider, $taskUid, 2, 'board-outsider-command-1', 'board.task.status', ['status' => 'review']
    );
} catch (\DomainException $error) {
    $outsiderDenied = $error->getCode() === 404;
}
integrityCheck($outsiderDenied, 'посторонний пользователь не узнаёт состояние приватной доски');

$assignees = $service->mutate(
    $member, $taskUid, 2, 'board-member-command-2', 'board.task.update',
    ['assignee_ids' => [$member, $userId, $member]]
);
integrityCheck($assignees['version'] === 3
    && $assignees['current']['assignee_ids'] === [$userId, $member],
    'изменение исполнителей атомарно увеличивает версию и убирает дубликаты');
$assigneesReplay = $service->mutate(
    $member, $taskUid, 2, 'board-member-command-2', 'board.task.update',
    ['assignee_ids' => [$userId, $member]]
);
integrityCheck(($assigneesReplay['replayed'] ?? false) === true,
    'повтор назначения не удаляет и не создаёт исполнителей второй раз');

// Даже завершённую команду нельзя прочитать после отзыва доступа.
$removeMember = $pdo->prepare('DELETE FROM task_board_members WHERE board_id=:board AND user_id=:actor');
$removeMember->execute([':board' => $boardId, ':actor' => $member]);
$revokedDenied = false;
try {
    $service->mutate(
        $member, $taskUid, 2, 'board-member-command-2', 'board.task.update',
        ['assignee_ids' => [$userId, $member]]
    );
} catch (\DomainException $error) {
    $revokedDenied = $error->getCode() === 404;
}
integrityCheck($revokedDenied, 'отзыв доступа запрещает повтор ранее выполненной команды');
$membership->execute([':board' => $boardId, ':actor' => $member, ':role' => 'member']);

$archive = $service->mutate(
    $userId, $taskUid, 3, 'board-owner-command-3', 'board.task.archive', ['archived' => '1']
);
integrityCheck($archive['version'] === 4 && $archive['current']['archived_at'] !== null,
    'архивация использует тот же версионный контракт');
$archiveReplay = $service->mutate(
    $userId, $taskUid, 3, 'board-owner-command-3', 'board.task.archive', ['archived' => '1']
);
integrityCheck($archiveReplay['replayed'] === true,
    'повтор архивации не изменяет время и версию');

$deleted = $service->mutate(
    $userId, $taskUid, 4, 'board-owner-command-4', 'board.task.delete', ['delete' => '1']
);
integrityCheck($deleted['version'] === 5 && $deleted['current']['is_deleted'] === 1,
    'удаление увеличивает версию');
$deleteReplay = $service->mutate(
    $userId, $taskUid, 4, 'board-owner-command-4', 'board.task.delete', ['delete' => '1']
);
integrityCheck($deleteReplay['replayed'] === true && $deleteReplay['version'] === 5,
    'повтор удаления безопасен даже после пометки is_deleted');

$deletedAgainDenied = false;
try {
    $service->mutate(
        $userId, $taskUid, 5, 'board-owner-command-5', 'board.task.delete', ['delete' => '1']
    );
} catch (\DomainException $error) {
    $deletedAgainDenied = $error->getCode() === 404;
}
integrityCheck($deletedAgainDenied, 'новая команда не изменяет удалённую задачу');

$check = $pdo->prepare('SELECT version FROM task_board_items WHERE uid=:uid');
$check->execute([':uid' => $taskUid]);
integrityCheck((int) $check->fetchColumn() === 5,
    'версия общей задачи равна числу применённых команд плюс один');

echo 'Контракт целостности задач общих досок 1.2 выполнен' . PHP_EOL;
