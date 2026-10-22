<?php
declare(strict_types=1);

/**
 * Контракт маршрутов API досок.
 * Реальные проверки доступа и версий находятся в платформенных интеграционных тестах сервиса досок.
 */
$root = dirname(__DIR__, 2);
$api = file_get_contents($root . '/modules/tasks/controllers/TaskBoardApiController.php');
$routes = file_get_contents($root . '/modules/tasks/TasksRuntimeProvider.php');
$runtime = file_get_contents($root . '/modules/tasks/runtime.php');
$service = file_get_contents($root . '/modules/tasks/services/TaskBoardVersionMutationService.php');

function boardApiAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('Контракт API общих досок: ' . $message);
    }
}

foreach (['api', 'routes', 'runtime', 'service'] as $name) {
    boardApiAssert(is_string($$name), 'не удалось прочитать ' . $name);
}
foreach ([
    "'GET', '/', [TaskBoardApiController::class, 'boards']",
    "'GET', '/{str:boardUid}/tasks', [TaskBoardApiController::class, 'tasks']",
    "'PATCH', '/{str:taskUid}', [TaskBoardApiController::class, 'update']",
] as $route) {
    boardApiAssert(str_contains($routes, $route), 'отсутствует маршрут ' . $route);
}
boardApiAssert(str_contains($runtime, '/controllers/TaskBoardApiController.php'),
    'контроллер не включён в модуль');
boardApiAssert(str_contains($api, "TaskApiTokenService::SCOPE_WRITE"),
    'для изменений не требуется право записи');
boardApiAssert(str_contains($api, 'TaskBoardVersionMutationService(DatabaseManager::getInstance())'),
    'изменение не использует штатные версионные команды');
boardApiAssert(str_contains($api, '->boardForUser($owner, $boardUid)'),
    'чтение обходит ACL существующего сервиса');
boardApiAssert(str_contains($api, 'is_deleted=0'),
    'удалённые элементы попадают в список');
boardApiAssert(str_contains($api, "'next_after_id'"),
    'отсутствует продолжение списка');
boardApiAssert(str_contains($api, "TaskApiTrafficService"),
    'новые маршруты обходят квоты и журнал');
boardApiAssert(str_contains($service, 'assertCanWrite($actorId, $task)'),
    'штатная команда больше не проверяет роль');
boardApiAssert(str_contains($service, "'version_conflict'"),
    'штатная команда утратила проверку версии');

echo "Контракт API досок: маршруты, ACL, ключи, постраничная выдача и версии проверены\n";
