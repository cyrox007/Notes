<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\TaskModel;
use App\Models\TaskCategoryModel;
use App\Models\SubtaskModel;
use App\Models\TaskReminderModel;
use App\Models\UserModel;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\Router;

class TaskController extends Controller
{
    /**
     * Главная страница задач - список всех задач пользователя
     */
    public function index(Request $request): void
    {
        $user = UserModel::select()
            ->where('id', '=', $request->session('user_id'))
            ->first();

        $filter = $request->get('filter') ?? 'all';
        $sort = $request->get('sort') ?? 'created_at';
        $direction = $request->get('direction') ?? 'desc';

        // Базовый запрос
        $query = TaskModel::select('tasks.*')
            ->where('tasks.user_id', '=', $user->id)
            ->where('tasks.is_deleted', '=', 0);

        // Фильтрация по статусу
        if ($filter === 'today') {
            $today = date('Y-m-d');
            $query->whereDate('due_date', '=', $today);
        } elseif ($filter === 'week') {
            $query->whereBetween('due_date', date('Y-m-d'), date('Y-m-d', strtotime('+7 days')));
        } elseif ($filter === 'overdue') {
            $query->where('due_date', '<', date('Y-m-d H:i:s'))
                  ->whereNotIn('status', ['completed', 'cancelled']);
        } elseif (in_array($filter, ['pending', 'in_progress', 'completed', 'cancelled'])) {
            $query->where('status', '=', $filter);
        }

        // Сортировка
        $query->orderBy($sort, $direction);

        $tasks = $query->get();

        // Получаем категории пользователя
        $categories = TaskCategoryModel::select()
            ->where('is_deleted', '=', 0)
            ->orderBy('sort_order', 'ASC')
            ->get();

        // Статистика
        $stats = [
            'total' => count($tasks),
            'pending' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'overdue' => 0,
        ];

        foreach ($tasks as $task) {
            if (isset($stats[$task->status])) {
                $stats[$task->status]++;
            }
            if ($task->isOverdue()) {
                $stats['overdue']++;
            }
        }

        $data = [
            'tasks' => $tasks,
            'categories' => $categories,
            'user' => $user,
            'currentFilter' => $filter,
            'stats' => $stats,
        ]; 
        
        $this->render_template('tasks_page/index', $data);
    }

    /**
     * Создание новой задачи
     */
    public function create(Request $request): void
    {
        $user = UserModel::select()->where('uid', '=', $request->session('user_uid'))->first();

        $uidTask = bin2hex(random_bytes(16));
        $createdAt = date('Y-m-d H:i:s');
        
        $newTask = new TaskModel();
        $newTask->uid = $uidTask;
        $newTask->title = $request->post('title');
        $newTask->description = $request->post('description') ?? null;
        $newTask->status = $request->post('status') ?? 'pending';
        $newTask->priority = $request->post('priority') ?? 'medium';
        $newTask->due_date = $request->post('due_date') ?: null;
        $newTask->user_id = $user->id;

        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueInsert([
            'uid' => $newTask->uid,
            'user_id' => $newTask->user_id,
            'title' => $newTask->title,
            'description' => $newTask->description,
            'status' => $newTask->status,
            'priority' => $newTask->priority,
            'due_date' => $newTask->due_date,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], 'tasks');
        $dbManager->commit();

        Router::getInstance()->redirect('tasks', 'name');
    }

    /**
     * Обновление задачи
     */
    public function update(Request $request, string $uid): void
    {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();

        $task = TaskModel::select()->where('uid', '=', $uid)->first();
        
        if (!$task || $task->user_id !== $user->id) {
            Router::getInstance()->redirect('tasks', 'name');
            return;
        }

        $dbManager = DatabaseManager::getInstance();
        
        $updateData = [
            'title' => $request->post('title'),
            'description' => $request->post('description') ?: null,
            'status' => $request->post('status') ?? $task->status,
            'priority' => $request->post('priority') ?? $task->priority,
            'due_date' => $request->post('due_date') ?: null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // Автоматическая установка completed_at при завершении
        if ($updateData['status'] === 'completed' && $task->status !== 'completed') {
            $updateData['completed_at'] = date('Y-m-d H:i:s');
        }

        $dbManager->queueUpdate($updateData, 'tasks', (int) $task->id);
        $dbManager->commit();
        
        Router::getInstance()->redirect('tasks', 'name');
    }

    /**
     * Удаление задачи
     */
    public function delete(Request $request, string $uid): void
    {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();

        $task = TaskModel::select()->where('uid', '=', $uid)->first();

        if (!$task || $task->user_id !== $user->id) {
            Router::getInstance()->redirect('tasks', 'name');
            return;
        }

        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueUpdate([
            'is_deleted' => 1,
            'deleted_at' => date('Y-m-d H:i:s'),
        ], 'tasks', (int) $task->id);

        $dbManager->commit();
        
        Router::getInstance()->redirect('tasks', 'name');
    }

    /**
     * Добавление подзадачи
     */
    public function addSubtask(Request $request, string $taskUid): void
    {
        header('Content-Type: application/json');
        
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();
        
        $task = TaskModel::select()->where('uid', '=', $taskUid)->where('is_deleted', '=', 0)->first();
        
        if (!$task || $task->user_id !== $user->id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
            return;
        }
        
        $title = trim($request->post('title'));
        if (empty($title)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Название подзадачи не может быть пустым']);
            return;
        }
        
        $subtask = new SubtaskModel();
        $subtask->task_id = $task->id;
        $subtask->title = $title;
        $subtask->is_completed = 0;
        
        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueInsert([
            'task_id' => $subtask->task_id,
            'title' => $subtask->title,
            'is_completed' => $subtask->is_completed,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'subtasks');
        $dbManager->commit();
        
        echo json_encode([
            'success' => true,
            'subtask' => [
                'id' => $subtask->id,
                'title' => $subtask->title,
                'is_completed' => $subtask->is_completed,
            ]
        ]);
    }

    /**
     * Переключение статуса подзадачи
     */
    public function toggleSubtask(Request $request, int $subtaskId): void
    {
        header('Content-Type: application/json');
        
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();
        
        $subtask = SubtaskModel::select()
            ->innerJoin([TaskModel::class, 'task'], 'subtasks.task_id', '=', 'tasks.id')
            ->where('subtasks.id', '=', $subtaskId)
            ->first();
        
        if (!$subtask || $task->user_id !== $user->id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
            return;
        }
        
        $newStatus = $subtask->is_completed ? 0 : 1;
        $completedAt = $newStatus ? date('Y-m-d H:i:s') : null;
        
        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueUpdate([
            'is_completed' => $newStatus,
            'completed_at' => $completedAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'subtasks', $subtaskId);
        $dbManager->commit();
        
        echo json_encode([
            'success' => true,
            'is_completed' => $newStatus,
        ]);
    }

    /**
     * Удаление подзадачи
     */
    public function deleteSubtask(Request $request, int $subtaskId): void
    {
        header('Content-Type: application/json');
        
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();
        
        $subtask = SubtaskModel::select()
            ->innerJoin([TaskModel::class, 'task'], 'subtasks.task_id', '=', 'tasks.id')
            ->where('subtasks.id', '=', $subtaskId)
            ->first();
        
        if (!$subtask || $task->user_id !== $user->id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
            return;
        }
        
        $dbManager = DatabaseManager::getInstance();
        $dbManager->delete('subtasks', $subtaskId);
        $dbManager->commit();
        
        echo json_encode(['success' => true]);
    }

    /**
     * Создание категории
     */
    public function createCategory(Request $request): void
    {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();
        
        $name = trim($request->post('name'));
        if (empty($name)) {
            Router::getInstance()->redirect('tasks', 'name');
            return;
        }
        
        $category = new TaskCategoryModel();
        $category->user_id = $user->id;
        $category->name = $name;
        $category->color = $request->post('color') ?? '#3498db';
        $category->icon = $request->post('icon') ?? 'fa-folder';
        
        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueInsert([
            'user_id' => $category->user_id,
            'name' => $category->name,
            'color' => $category->color,
            'icon' => $category->icon,
            'created_at' => date('Y-m-d H:i:s'),
        ], 'task_categories');
        $dbManager->commit();
        
        Router::getInstance()->redirect('tasks', 'name');
    }

    /**
     * Привязка категории к задаче
     */
    public function attachCategory(Request $request, string $taskUid, int $categoryId): void
    {
        header('Content-Type: application/json');
        
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();
        
        $task = TaskModel::select()->where('uid', '=', $taskUid)->where('is_deleted', '=', 0)->first();
        
        if (!$task || $task->user_id !== $user->id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
            return;
        }
        
        $category = TaskCategoryModel::select()->where('id', '=', $categoryId)->first();
        
        if (!$category || ($category->user_id !== $user->id && $category->user_id !== null)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Категория не найдена']);
            return;
        }
        
        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueInsert([
            'task_id' => $task->id,
            'category_id' => $categoryId,
            'created_at' => date('Y-m-d H:i:s'),
        ], 'task_category_relations');
        $dbManager->commit();
        
        echo json_encode(['success' => true]);
    }

    /**
     * Отвязка категории от задачи
     */
    public function detachCategory(Request $request, string $taskUid, int $categoryId): void
    {
        header('Content-Type: application/json');
        
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();
        
        $task = TaskModel::select()->where('uid', '=', $taskUid)->where('is_deleted', '=', 0)->first();
        
        if (!$task || $task->user_id !== $user->id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
            return;
        }
        
        $dbManager = DatabaseManager::getInstance();
        $dbManager->deleteWhere('task_category_relations', [
            'task_id' => $task->id,
            'category_id' => $categoryId,
        ]);
        $dbManager->commit();
        
        echo json_encode(['success' => true]);
    }
}
