<?php

declare(strict_types=1);

namespace App\Models;

use Core\DatabaseManager;
use Core\ORM;

class TaskModel extends ORM
{
    protected ?string $_tablename = 'tasks';

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

    /** @var list<TaskCategoryModel> */
    public array $categories = [];

    /** @var list<SubtaskModel> */
    public array $subtasks = [];

    /** @var list<TaskReminderModel> */
    public array $reminders = [];

    public function __construct()
    {
        $this->author = new UserModel();
    }

    /** @return list<TaskCategoryModel> */
    public function getCategories(): array
    {
        if ($this->id <= 0) {
            return [];
        }

        $categories = TaskCategoryModel::select('task_categories.*')
            ->innerJoin([TaskCategoryRelationModel::class, 'relation'], 'task_categories.id', '=', 'relation.category_id')
            ->where('relation.task_id', '=', $this->id)
            ->where('task_categories.is_deleted', '=', 0)
            ->get();

        return $categories ?: [];
    }

    /** @return list<SubtaskModel> */
    public function getSubtasks(): array
    {
        if ($this->id <= 0) {
            return [];
        }

        $subtasks = SubtaskModel::select()
            ->where('task_id', '=', $this->id)
            ->orderBy('sort_order', 'ASC')
            ->get();

        return $subtasks ?: [];
    }

    /** @return list<TaskReminderModel> */
    public function getReminders(): array
    {
        if ($this->id <= 0) {
            return [];
        }

        $reminders = TaskReminderModel::select()
            ->where('task_id', '=', $this->id)
            ->orderBy('reminder_time', 'ASC')
            ->get();

        return $reminders ?: [];
    }

    public function canAccess(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    public function complete(): void
    {
        $this->status = 'completed';
        $this->completed_at = date('Y-m-d H:i:s');

        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueUpdate([
            'status' => $this->status,
            'completed_at' => $this->completed_at,
        ], 'tasks', $this->id);
        $dbManager->commit();
    }

    public function getCompletionPercentage(): float
    {
        $subtasks = $this->getSubtasks();
        if ($subtasks === []) {
            return 0.0;
        }

        $completed = 0;
        foreach ($subtasks as $subtask) {
            if ($subtask->is_completed !== 0) {
                $completed++;
            }
        }

        return ($completed / count($subtasks)) * 100;
    }

    public function isOverdue(): bool
    {
        if ($this->due_date === null || $this->due_date === '') {
            return false;
        }
        if (in_array($this->status, ['completed', 'cancelled'], true)) {
            return false;
        }

        return strtotime($this->due_date) < time();
    }

    public function getPriorityColor(): string
    {
        return match ($this->priority) {
            'low' => '#95a5a6',
            'high' => '#f39c12',
            'urgent' => '#e74c3c',
            'medium' => '#3498db',
            default => '#3498db',
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Ожидает',
            'in_progress' => 'В процессе',
            'completed' => 'Завершена',
            'cancelled' => 'Отменена',
            default => $this->status,
        };
    }
}
