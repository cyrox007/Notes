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

$views = [
    'app/views/tasks_page/index.php',
    'app/views/tasks_page/boards.php',
    'app/views/^elements/task_item/index.php',
];
foreach ($views as $relative) {
    $path = $root . '/' . $relative;
    nativeTasksAssert(is_file($path), "missing native Tasks view: {$relative}");
    $source = file_get_contents($path);
    nativeTasksAssert(is_string($source), "cannot read native Tasks view: {$relative}");
    nativeTasksAssert(!str_contains($source, '{extends'), "{$relative} still contains Smarty extends syntax");
    nativeTasksAssert(!str_contains($source, '{include'), "{$relative} still contains Smarty include syntax");
    nativeTasksAssert(!str_contains($source, '{foreach'), "{$relative} still contains Smarty foreach syntax");
    nativeTasksAssert(!str_contains($source, '{if'), "{$relative} still contains Smarty conditional syntax");
    nativeTasksAssert(!str_contains($source, '$smarty'), "{$relative} still depends on Smarty runtime state");
}

$index = (string) file_get_contents($root . '/app/views/tasks_page/index.php');
nativeTasksAssert(str_contains($index, '$view->layout(\'core/base\''), 'Tasks page does not use native application shell');
foreach (['task_create', 'create_category', 'task_boards'] as $route) {
    nativeTasksAssert(str_contains($index, "route('{$route}'"), "Tasks page route {$route} is missing");
}
nativeTasksAssert(str_contains($index, '$view->csrfInput()'), 'Tasks create/category forms lost CSRF inputs');
nativeTasksAssert(str_contains($index, "partial('^elements/task_item/index'"), 'native task item partial is not used');
nativeTasksAssert(str_contains($index, 'data-shared-task-boards-link'), 'shared task boards navigation hook is missing');
nativeTasksAssert(str_contains($index, '/assets/js/tasks-page.js'), 'static Tasks behavior bundle is missing');
foreach (['stat-pending', 'stat-in_progress', 'stat-completed', 'stat-overdue'] as $hook) {
    nativeTasksAssert(str_contains($index, $hook), "Tasks stat hook {$hook} is missing");
}

$item = (string) file_get_contents($root . '/app/views/^elements/task_item/index.php');
foreach (['delete_task', 'update_task'] as $route) {
    nativeTasksAssert(str_contains($item, "route('{$route}'"), "task item route {$route} is missing");
}
nativeTasksAssert(str_contains($item, '$view->csrfInput()'), 'task edit/delete forms lost CSRF inputs');
foreach ([
    'class="task-status-toggle"',
    'class="task-complete-toggle"',
    'task-status-label',
    'task-category-select',
    'attach-category-btn',
    'detach-category-btn',
    'task-subtasks__percent',
    'task-subtasks__progress',
    'task-subtasks__progress-bar',
    'subtask-toggle',
    'delete-subtask',
    'data-task-edit-panel',
] as $hook) {
    nativeTasksAssert(str_contains($item, $hook), "task item DOM hook {$hook} is missing");
}
nativeTasksAssert(str_contains($item, '$view->e($title)'), 'task title is not escaped');
nativeTasksAssert(str_contains($item, '$view->e($description)'), 'task description is not escaped in edit form');

$boards = (string) file_get_contents($root . '/app/views/tasks_page/boards.php');
nativeTasksAssert(str_contains($boards, '$view->layout(\'core/base\''), 'shared boards page does not use native shell');
foreach (['task_board_create', 'task_board_members', 'task_board_task_create', 'task_board_task_update', 'task_board_task_delete'] as $route) {
    nativeTasksAssert(str_contains($boards, "route('{$route}'"), "shared boards route {$route} is missing");
}
nativeTasksAssert(str_contains($boards, '$view->csrfInput()'), 'shared board mutating forms lost CSRF inputs');
foreach (['$canCreateBoards', '$canAssignTasks', '$canWrite', '$canManage'] as $policyGuard) {
    nativeTasksAssert(str_contains($boards, $policyGuard), "shared board policy guard {$policyGuard} is missing");
}
nativeTasksAssert(str_contains($boards, 'value="all_active"'), 'all-active board audience is missing');
nativeTasksAssert(str_contains($boards, 'data-update-url='), 'shared board drag/drop update URL hook is missing');
nativeTasksAssert(str_contains($boards, '/assets/js/task-boards.js'), 'static shared board behavior bundle is missing');
nativeTasksAssert(str_contains($boards, '$view->e($boardTask[\'title\'] ?? \'\')'), 'shared task title is not escaped');

$tasksJsPath = $root . '/assets/js/tasks-page.js';
$boardsJsPath = $root . '/assets/js/task-boards.js';
foreach ([$tasksJsPath, $boardsJsPath] as $path) {
    nativeTasksAssert(is_file($path), 'missing static Tasks JavaScript: ' . basename($path));
    $source = (string) file_get_contents($path);
    nativeTasksAssert(!str_contains($source, '{literal}'), basename($path) . ' still contains Smarty literal markers');
}
$tasksJs = (string) file_get_contents($tasksJsPath);
nativeTasksAssert(str_contains($tasksJs, 'window.wspace?.path'), 'Tasks API paths are not BASE_PATH-aware');
nativeTasksAssert(str_contains($tasksJs, 'syncSubtaskProgress'), 'subtask rollback/progress synchronization is missing');

$kanban = (string) file_get_contents($root . '/assets/js/tasks-kanban.js');
nativeTasksAssert(str_contains($kanban, 'shiftStats'), 'live Tasks stats synchronization is missing');
nativeTasksAssert(str_contains($kanban, 'updateSubtaskProgress'), 'live subtask progress synchronization is missing');

$boardController = (string) file_get_contents($root . '/app/controllers/TaskBoardController.php');
nativeTasksAssert(str_contains($boardController, 'TaskBoardService'), 'shared boards no longer enforce the TaskBoardService boundary');
nativeTasksAssert(str_contains($boardController, "'boardPolicies' => \$policies"), 'shared board policy snapshot is not passed to the view');

$taskController = (string) file_get_contents($root . '/app/controllers/TaskController.php');
nativeTasksAssert(str_contains($taskController, 't.user_id = :user_id'), 'personal Tasks ownership query boundary is missing');
nativeTasksAssert(str_contains($taskController, "'completion_percentage'"), 'authoritative subtask progress calculation is missing');

echo "[OK] native Tasks views contract\n";
