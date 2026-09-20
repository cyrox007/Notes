<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

function nativeTasksAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$moduleRoot = $root . '/modules/tasks';
$views = [
    'modules/tasks/views/index.php',
    'modules/tasks/views/boards.php',
    'modules/tasks/views/elements/task_item/index.php',
];
foreach ($views as $relative) {
    $path = $root . '/' . $relative;
    nativeTasksAssert(is_file($path), "missing isolated Tasks view: {$relative}");
    $source = (string) file_get_contents($path);
    nativeTasksAssert(!str_contains($source, '{extends'), "{$relative} still contains Smarty syntax");
    nativeTasksAssert(!str_contains($source, '$smarty'), "{$relative} still depends on Smarty runtime state");
}

foreach ([
    'app/controllers/TaskController.php',
    'app/controllers/TaskBoardController.php',
    'app/services/TaskBoardService.php',
    'app/middlewares/RequireTasksUse.php',
    'app/middlewares/EnforceTaskCreatePolicy.php',
    'app/views/tasks_page/index.php',
    'app/views/tasks_page/index.tpl',
    'app/views/tasks_page/boards.php',
    'app/views/tasks_page/boards.tpl',
    'app/views/^elements/task_item/index.php',
    'app/views/^elements/task_item/index.tpl',
    'assets/js/tasks-page.js',
    'assets/js/tasks-kanban.js',
    'assets/js/task-boards.js',
    'assets/js/task-boards-nav.js',
] as $legacy) {
    nativeTasksAssert(!file_exists($root . '/' . $legacy), "legacy Tasks runtime artifact remains: {$legacy}");
}

$manifest = json_decode((string) file_get_contents($moduleRoot . '/module.json'), true, 32, JSON_THROW_ON_ERROR);
nativeTasksAssert(($manifest['runtime']['mode'] ?? '') === 'isolated', 'Tasks manifest is not isolated');
nativeTasksAssert(($manifest['runtime']['entrypoint'] ?? '') === 'runtime.php', 'Tasks runtime entrypoint is missing');
nativeTasksAssert(is_file($moduleRoot . '/runtime.php'), 'Tasks runtime.php is missing');
nativeTasksAssert(is_file($moduleRoot . '/TasksRuntimeProvider.php'), 'Tasks provider is missing');
nativeTasksAssert(is_file($moduleRoot . '/TasksCapability.php'), 'Tasks capability service is missing');

$provider = (string) file_get_contents($moduleRoot . '/TasksRuntimeProvider.php');
nativeTasksAssert(str_contains($provider, "return 'tasks';"), 'Tasks provider id drifted');
nativeTasksAssert(str_contains($provider, "'workspace.tasks' =>"), 'Tasks runtime capability export missing');
nativeTasksAssert(str_contains($provider, "group('/tasks')"), 'Tasks routes are not module-owned');
foreach (['tasks', 'task_boards', 'task_board_task_update', 'create_category'] as $route) {
    nativeTasksAssert(str_contains($provider, "'{$route}'"), "Tasks provider route {$route} is missing");
}

$coreRoutes = (string) file_get_contents($root . '/core/routerConfig.php');
nativeTasksAssert(!str_contains($coreRoutes, "group('/tasks')"), 'core router still owns Tasks routes');
nativeTasksAssert(!str_contains($coreRoutes, 'TaskController'), 'core router still imports TaskController');

$index = (string) file_get_contents($moduleRoot . '/views/index.php');
nativeTasksAssert(str_contains($index, '$view->layout(\'core/base\''), 'Tasks page does not use native shell');
nativeTasksAssert(str_contains($index, "partial('@tasks/elements/task_item/index'"), 'isolated task item partial is not used');
nativeTasksAssert(str_contains($index, "moduleAsset('tasks', 'tasks-page.js')"), 'Tasks page JS is not module-owned');
nativeTasksAssert(str_contains($index, "moduleAsset('tasks', 'style.css')"), 'Tasks page CSS is not module-owned');
foreach (['task_create', 'create_category', 'task_boards'] as $route) {
    nativeTasksAssert(str_contains($index, "route('{$route}'"), "Tasks page route {$route} is missing");
}
nativeTasksAssert(str_contains($index, '$view->csrfInput()'), 'Tasks forms lost CSRF inputs');
nativeTasksAssert(str_contains($index, 'data-shared-task-boards-link'), 'shared boards navigation hook is missing');
nativeTasksAssert(str_contains($index, 'data-task-modal-close'), 'task modal close controls are not explicit');
nativeTasksAssert(!str_contains($index, 'btn-secondary close-modal'), 'task modal cancel action still inherits close-icon geometry');

$item = (string) file_get_contents($moduleRoot . '/views/elements/task_item/index.php');
foreach (['delete_task', 'update_task'] as $route) {
    nativeTasksAssert(str_contains($item, "route('{$route}'"), "task item route {$route} is missing");
}
foreach (['task-status-toggle','task-complete-toggle','task-category-select','attach-category-btn','subtask-toggle','data-task-edit-panel'] as $hook) {
    nativeTasksAssert(str_contains($item, $hook), "task item DOM hook {$hook} is missing");
}

$boards = (string) file_get_contents($moduleRoot . '/views/boards.php');
nativeTasksAssert(str_contains($boards, "moduleAsset('tasks', 'boards.css')"), 'shared boards CSS is not module-owned');
nativeTasksAssert(str_contains($boards, "moduleAsset('tasks', 'task-boards.js')"), 'shared boards JS is not module-owned');
foreach (['task_board_create', 'task_board_members', 'task_board_task_create', 'task_board_task_update', 'task_board_task_delete'] as $route) {
    nativeTasksAssert(str_contains($boards, "route('{$route}'"), "shared boards route {$route} is missing");
}

foreach (['tasks-page.js','tasks-kanban.js','task-boards.js','task-boards-nav.js','style.css','boards.css','hardening.css','kanban.css','kanban-handle.css'] as $asset) {
    nativeTasksAssert(is_file($moduleRoot . '/assets/' . $asset), "missing isolated Tasks asset: {$asset}");
}
$tasksJs = (string) file_get_contents($moduleRoot . '/assets/tasks-page.js');
nativeTasksAssert(str_contains($tasksJs, 'window.wspace?.path'), 'Tasks API paths are not BASE_PATH-aware');
nativeTasksAssert(str_contains($tasksJs, "querySelectorAll('[data-task-modal-close]')"), 'task modal JS does not bind explicit close controls');
nativeTasksAssert(str_contains($tasksJs, 'syncSubtaskProgress'), 'subtask progress synchronization is missing');
nativeTasksAssert(str_contains($tasksJs, "markSaveState(this, 'saving')"), 'task status save feedback is missing');
$taskStyle = (string) file_get_contents($moduleRoot . '/assets/style.css');
nativeTasksAssert(str_contains($taskStyle, '.modal-close-button{'), 'task modal close icon style is missing');
nativeTasksAssert(!str_contains($taskStyle, '.close-modal{'), 'modal cancel button still shares close-icon styling');

$kanban = (string) file_get_contents($moduleRoot . '/assets/tasks-kanban.js');
nativeTasksAssert(str_contains($kanban, 'shiftStats'), 'live Tasks stats synchronization is missing');

$boardController = (string) file_get_contents($moduleRoot . '/controllers/TaskBoardController.php');
nativeTasksAssert(str_contains($boardController, "render_template('@tasks/boards'"), 'board controller is not using isolated view namespace');
nativeTasksAssert(str_contains($boardController, 'TaskBoardService'), 'shared boards service boundary is missing');
$taskController = (string) file_get_contents($moduleRoot . '/controllers/TaskController.php');
nativeTasksAssert(str_contains($taskController, "render_template('@tasks/index'"), 'Task controller is not using isolated view namespace');
nativeTasksAssert(str_contains($taskController, 't.user_id = :user_id'), 'personal Tasks ownership query boundary is missing');

$capability = (string) file_get_contents($moduleRoot . '/TasksCapability.php');
nativeTasksAssert(str_contains($capability, 'WorkspaceTaskCreator'), 'Tasks capability does not expose the shared workspace creation boundary');
nativeTasksAssert(str_contains($capability, 'createWorkspaceTask('), 'Tasks capability cannot create tasks for cross-module actions');
nativeTasksAssert(str_contains($capability, "requirePermission(\$userId, 'tasks.use')"), 'cross-module task creation bypasses Tasks RBAC');
nativeTasksAssert(str_contains($capability, "'tasks', 'max_personal_tasks'"), 'cross-module task creation bypasses the personal task limit');

$base = (string) file_get_contents($root . '/app/views/core/base.php');
nativeTasksAssert(!str_contains($base, 'tasks_page/'), 'shared shell still inlines Tasks styles');
nativeTasksAssert(!str_contains($base, '/assets/js/tasks-kanban.js'), 'shared shell still loads Tasks kanban globally');
nativeTasksAssert(!str_contains($base, '/assets/js/task-boards-nav.js'), 'shared shell still loads Tasks navigation globally');

echo "[OK] isolated Tasks runtime contract\n";
