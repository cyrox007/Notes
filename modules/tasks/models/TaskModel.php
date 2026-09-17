<?php

namespace App\Models;

use Core\ORM;

class TaskModel extends ORM {
    protected ?string $_tablename = "tasks";

    public int $id = 0;
    public string $uid = '';
    public int $user_id = 0;
    public string $title = '';
    public ?string $description = null;
    public string $status = 'pending';
    public string $priority = 'medium';
    public ?string $due_date = null;
    public ?string $completed_at = null;
    public int $is_deleted = 0;
    public ?string $deleted_at = null;
    public string $created_at = '';
    public string $updated_at = '';
    
    public ?UserModel $author = null;
    public array $categories = [];
    public array $subtasks = [];
    public array $reminders = [];

    public function __construct() {
        $this->author = new UserModel();
    }

    /**
     * Получить категории задачи
     */
    public function getCategories(): array {
        if (empty($this->id)) {
            return [];
        }
        
        $categories = TaskCategoryModel::select('task_categories.*')
            ->innerJoin([TaskCategoryRelationModel::class, 'relation'], 'task_categories.id', '=', 'relation.category_id')
            ->where('relation.task_id', '=', $this->id)
            ->where('task_categories.is_deleted', '=', 0)
            ->get();
        
        return $categories ?: [];
    }

    /**
     * Получить подзадачи
     */
    public function getSubtasks(): array {
        if (empty($this->id)) {
            return [];
        }
        
        $subtasks = SubtaskModel::select()
            ->where('task_id', '=', $this->id)
            ->orderBy('sort_order', 'ASC')
            ->get();
        
        return $subtasks ?: [];
    }

    /**
     * Получить напоминания
     */
    public function getReminders(): array {
        if (empty($this->id)) {
            return [];
        }
        
        $reminders = TaskReminderModel::select()
            ->where('task_id', '=', $this->id)
            ->orderBy('reminder_time', 'ASC')
            ->get();
        
        return $reminders ?: [];
    }

    /**
     * Проверить доступ к задаче
     */
    public function canAccess(int $userId): bool {
        return $this->user_id === $userId;
    }

    /**
     * Завершить задачу
     */
    public function complete(): void {
        $this->status = 'completed';
        $this->completed_at = date('Y-m-d H:i:s');
        
        $dbManager = \Core\DatabaseManager::getInstance();
        $dbManager->queueUpdate([
            'status' => $this->status,
            'completed_at' => $this->completed_at,
        ], 'tasks', $this->id);
        $dbManager->commit();
    }

    /**
     * Получить процент выполнения подзадач
     */
    public function getCompletionPercentage(): float {
        $subtasks = $this->getSubtasks();
        
        if (empty($subtasks)) {
            return 0.0;
        }
        
        $completed = 0;
        foreach ($subtasks as $subtask) {
            if ($subtask->is_completed) {
                $completed++;
            }
        }
        
        return ($completed / count($subtasks)) * 100;
    }

    /**
     * Проверить просрочена ли задача
     */
    public function isOverdue(): bool {
        if (!$this->due_date || $this->status === 'completed' || $this->status === 'cancelled') {
            return false;
        }
        
        return strtotime($this->due_date) < time();
    }

    /**
     * Получить цвет приоритета
     */
    public function getPriorityColor(): string {
        $colors = [
            'low' => '#95a5a6',
            'medium' => '#3498db',
            'high' => '#f39c12',
            'urgent' => '#e74c3c',
        ];
        
        return $colors[$this->priority] ?? '#3498db';
    }

    /**
     * Получить статус на русском
     */
    public function getStatusLabel(): string {
        $labels = [
            'pending' => 'Ожидает',
            'in_progress' => 'В процессе',
            'completed' => 'Завершена',
            'cancelled' => 'Отменена',
        ];
        
        return $labels[$this->status] ?? $this->status;
    }
}
