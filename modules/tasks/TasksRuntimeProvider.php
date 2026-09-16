<?php
declare(strict_types=1);

namespace Modules\Tasks;

use App\Controllers\TaskBoardController;
use App\Controllers\TaskController;
use App\Middlewares\CSRFMiddleware;
use App\Middlewares\EnforceTaskCreatePolicy;
use App\Middlewares\LoginRequared;
use App\Middlewares\RequireTasksUse;
use Core\ModuleRuntimeProvider;
use Core\Router;

final class TasksRuntimeProvider implements ModuleRuntimeProvider
{
    private TasksCapability $capability;

    public function __construct()
    {
        $this->capability = new TasksCapability();
    }

    public function moduleId(): string
    {
        return 'tasks';
    }

    public function boot(): void
    {
        // Module-owned classes are loaded explicitly by runtime.php.
    }

    /** @return array<string,object> */
    public function capabilities(): array
    {
        return ['workspace.tasks' => $this->capability];
    }

    public function registerRoutes(Router $router): void
    {
        $router->group('/tasks')
            ->add('GET', '/', [TaskController::class, 'index'], [LoginRequared::class, RequireTasksUse::class], 'tasks')
            ->add('POST', '/', [TaskController::class, 'create'], [LoginRequared::class, RequireTasksUse::class, EnforceTaskCreatePolicy::class], 'task_create')
            ->add('GET', '/boards', [TaskBoardController::class, 'index'], [LoginRequared::class, RequireTasksUse::class], 'task_boards')
            ->add('POST', '/boards', [TaskBoardController::class, 'createBoard'], [LoginRequared::class, RequireTasksUse::class, CSRFMiddleware::class], 'task_board_create')
            ->add('POST', '/boards/{str:uid}/members', [TaskBoardController::class, 'saveMembers'], [LoginRequared::class, RequireTasksUse::class, CSRFMiddleware::class], 'task_board_members')
            ->add('POST', '/boards/{str:uid}/tasks', [TaskBoardController::class, 'createTask'], [LoginRequared::class, RequireTasksUse::class, CSRFMiddleware::class], 'task_board_task_create')
            ->add('POST', '/boards/task/{str:uid}/update', [TaskBoardController::class, 'updateTask'], [LoginRequared::class, RequireTasksUse::class, CSRFMiddleware::class], 'task_board_task_update')
            ->add('POST', '/boards/task/{str:uid}/delete', [TaskBoardController::class, 'deleteTask'], [LoginRequared::class, RequireTasksUse::class, CSRFMiddleware::class], 'task_board_task_delete')
            ->add('POST', '/{str:uid}/update', [TaskController::class, 'update'], [LoginRequared::class, RequireTasksUse::class], 'update_task')
            ->add('POST', '/{str:uid}/delete', [TaskController::class, 'delete'], [LoginRequared::class, RequireTasksUse::class], 'delete_task')
            ->add('POST', '/{str:taskUid}/subtask', [TaskController::class, 'addSubtask'], [LoginRequared::class, RequireTasksUse::class], 'add_subtask')
            ->add('POST', '/subtask/{int:subtaskId}/toggle', [TaskController::class, 'toggleSubtask'], [LoginRequared::class, RequireTasksUse::class], 'toggle_subtask')
            ->add('POST', '/subtask/{int:subtaskId}/delete', [TaskController::class, 'deleteSubtask'], [LoginRequared::class, RequireTasksUse::class], 'delete_subtask')
            ->add('POST', '/category', [TaskController::class, 'createCategory'], [LoginRequared::class, RequireTasksUse::class], 'create_category')
            ->add('POST', '/{str:taskUid}/category/{int:categoryId}', [TaskController::class, 'attachCategory'], [LoginRequared::class, RequireTasksUse::class], 'attach_category')
            ->add('DELETE', '/{str:taskUid}/category/{int:categoryId}', [TaskController::class, 'detachCategory'], [LoginRequared::class, RequireTasksUse::class], 'detach_category')
            ->endGroup();
    }
}
