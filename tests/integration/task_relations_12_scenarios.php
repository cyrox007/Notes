<?php

declare(strict_types=1);

// Контракт запускается после создания БД и существующих проверок целостности.
require_once $root . '/modules/tasks/services/TaskRelationMutationService.php';

use App\Services\Tasks\TaskRelationMutationService;

$relations = new TaskRelationMutationService($pdo, new MutationCommandStore());
$relationsUid = bin2hex(random_bytes(16));
$createTask = $pdo->prepare(
    "INSERT INTO tasks (uid,user_id,title,description,status,priority,version,created_at,updated_at)
     VALUES (:uid,:user_id,'Контракт подзадач',NULL,'pending','medium',1,NOW(),NOW())"
);
$createTask->execute([':uid' => $relationsUid, ':user_id' => $userId]);
$relationTaskId = (int) $pdo->lastInsertId();

$firstSubtask = $relations->mutateTask(
    $userId, $relationsUid, 1, 'relation-create-001', 'task.subtask.create',
    ['title' => 'Проверить обновление']
);
$subtaskId = (int) ($firstSubtask['subtask']['id'] ?? 0);
integrityCheck($subtaskId > 0 && $firstSubtask['version'] === 2,
    'первая подзадача создана с обновлением версии родителя');

$staleSubtask = $relations->mutateTask(
    $userId, $relationsUid, 1, 'relation-stale-001', 'task.subtask.create',
    ['title' => 'Устаревшее добавление']
);
integrityCheck(($staleSubtask['error'] ?? '') === 'version_conflict'
    && $staleSubtask['current_version'] === 2, 'вторая вкладка не добавляет подзадачу по старой версии');

$replayedCreate = $relations->mutateTask(
    $userId, $relationsUid, 1, 'relation-create-001', 'task.subtask.create',
    ['title' => 'Проверить обновление']
);
integrityCheck($replayedCreate['replayed'] === true
    && $replayedCreate['subtask']['id'] === $subtaskId, 'повтор добавления возвращает тот же ID');
$subtaskCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM subtasks WHERE task_id=' . $relationTaskId
)->fetchColumn();
integrityCheck($subtaskCount === 1, 'повтор не создал вторую подзадачу');

$completion = $relations->mutateTask(
    $userId, $relationsUid, 2, 'relation-complete-001', 'task.subtask.complete',
    ['subtask_id' => $subtaskId, 'is_completed' => 1]
);
integrityCheck($completion['version'] === 3 && $completion['is_completed'] === 1,
    'явное завершение подзадачи повышает версию');

$completionReplay = $relations->mutateTask(
    $userId, $relationsUid, 2, 'relation-complete-001', 'task.subtask.complete',
    ['subtask_id' => $subtaskId, 'is_completed' => 1]
);
integrityCheck($completionReplay['replayed'] === true && $completionReplay['is_completed'] === 1,
    'повтор завершения не переключает флажок обратно');

$noOp = $relations->mutateTask(
    $userId, $relationsUid, 3, 'relation-complete-002', 'task.subtask.complete',
    ['subtask_id' => $subtaskId, 'is_completed' => 1]
);
integrityCheck($noOp['version'] === 3, 'повторное задание уже достигнутого состояния не меняет версию');

$category = $relations->createCategory(
    $userId, 'relation-category-create-001', 'Контракт', '#3498db', 'fa-folder'
);
$categoryId = (int) ($category['category']['id'] ?? 0);
integrityCheck($categoryId > 0, 'категория создана');
$categoryReplay = $relations->createCategory(
    $userId, 'relation-category-create-001', 'Контракт', '#3498db', 'fa-folder'
);
integrityCheck($categoryReplay['replayed'] === true
    && (int) $categoryReplay['category']['id'] === $categoryId,
    'повтор создания категории возвращает прежний ID');

$attached = $relations->mutateTask(
    $userId, $relationsUid, 3, 'relation-attach-001', 'task.category.attach',
    ['category_id' => $categoryId]
);
integrityCheck($attached['version'] === 4 && $attached['attached'] === true,
    'назначение категории обновило версию задачи');
$attachedAgain = $relations->mutateTask(
    $userId, $relationsUid, 4, 'relation-attach-002', 'task.category.attach',
    ['category_id' => $categoryId]
);
integrityCheck($attachedAgain['version'] === 4,
    'дубликат назначения категории не изменяет версию');

$detached = $relations->mutateTask(
    $userId, $relationsUid, 4, 'relation-detach-001', 'task.category.detach',
    ['category_id' => $categoryId]
);
integrityCheck($detached['version'] === 5 && $detached['attached'] === false,
    'снятие категории увеличивает версию');

$deleted = $relations->mutateTask(
    $userId, $relationsUid, 5, 'relation-delete-001', 'task.subtask.delete',
    ['subtask_id' => $subtaskId]
);
integrityCheck($deleted['version'] === 6, 'удаление подзадачи увеличивает версию');
$deletedReplay = $relations->mutateTask(
    $userId, $relationsUid, 5, 'relation-delete-001', 'task.subtask.delete',
    ['subtask_id' => $subtaskId]
);
integrityCheck($deletedReplay['replayed'] === true && $deletedReplay['version'] === 6,
    'повтор физического удаления подзадачи безопасен');

$missing = false;
try {
    $relations->mutateTask(
        $userId, $relationsUid, 6, 'relation-delete-002', 'task.subtask.delete',
        ['subtask_id' => $subtaskId]
    );
} catch (\DomainException $error) {
    $missing = $error->getCode() === 404;
}
integrityCheck($missing, 'новая команда не может удалить уже отсутствующую подзадачу');

$unauthorized = false;
try {
    $relations->mutateTask(
        $member, $relationsUid, 6, 'relation-outsider-001', 'task.subtask.create',
        ['title' => 'Посторонняя подзадача']
    );
} catch (\DomainException $error) {
    $unauthorized = $error->getCode() === 404;
}
integrityCheck($unauthorized, 'чужой пользователь не меняет личную задачу');

$invalidReuse = false;
try {
    $relations->mutateTask(
        $userId, $relationsUid, 1, 'relation-create-001', 'task.subtask.create',
        ['title' => 'Изменённые параметры старой команды']
    );
} catch (\InvalidArgumentException) {
    $invalidReuse = true;
}
integrityCheck($invalidReuse, 'command_id нельзя повторно использовать с другим содержимым');

$versionCheck = $pdo->prepare('SELECT version FROM tasks WHERE uid=:uid');
$versionCheck->execute([':uid' => $relationsUid]);
integrityCheck((int) $versionCheck->fetchColumn() === 6,
    'версия родителя учитывает реальные изменения связанных сущностей');

echo 'Контракт подзадач и категорий 1.2 выполнен' . PHP_EOL;
