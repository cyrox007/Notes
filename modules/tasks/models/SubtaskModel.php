<?php

namespace App\Models;

use Core\ORM;

class SubtaskModel extends ORM {
    protected ?string $_tablename = "subtasks";

    public int $id = 0;
    public int $task_id = 0;
    public string $title = '';
    public int $is_completed = 0;
    public ?string $completed_at = null;
    public int $sort_order = 0;
    public string $created_at = '';
    public string $updated_at = '';
}
