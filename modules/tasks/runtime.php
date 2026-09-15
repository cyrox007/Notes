<?php

declare(strict_types=1);

use App\Controllers\TaskController;
use App\Middlewares\LoginRequared;
use Core\ModuleRouteProvider;
use Core\Router;

return new class implements ModuleRouteProvider {
    public function registerRoutes(Router $router): void
    {
        $router->group('/tasks')
            ->add('GET', '/', [TaskController::class, 'index'], [LoginRequared::class], 'tasks')
            ->add('POST', '/', [TaskController::class, 'create'], [LoginRequared::class], 'task_create')
            ->add('POST', '/{str:uid}/update', [TaskController::class, 'update'], [LoginRequared::class], 'update_task')
            ->add('POST', '/{str:uid}/delete', [TaskController::class, 'delete'], [LoginRequared::class], 'delete_task')
            ->add('POST', '/{str:taskUid}/subtask', [TaskController::class, 'addSubtask'], [LoginRequared::class], 'add_subtask')
            ->add('POST', '/subtask/{int:subtaskId}/toggle', [TaskController::class, 'toggleSubtask'], [LoginRequared::class], 'toggle_subtask')
            ->add('POST', '/subtask/{int:subtaskId}/delete', [TaskController::class, 'deleteSubtask'], [LoginRequared::class], 'delete_subtask')
            ->add('POST', '/category', [TaskController::class, 'createCategory'], [LoginRequared::class], 'create_category')
            ->add('POST', '/{str:taskUid}/category/{int:categoryId}', [TaskController::class, 'attachCategory'], [LoginRequared::class], 'attach_category')
            ->add('DELETE', '/{str:taskUid}/category/{int:categoryId}', [TaskController::class, 'detachCategory'], [LoginRequared::class], 'detach_category')
            ->endGroup();
    }
};
