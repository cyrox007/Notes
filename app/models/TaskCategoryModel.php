<?php

namespace App\Models;

use Core\ORM;

class TaskCategoryModel extends ORM {
    protected ?string $_tablename = "task_categories";

    public int $id = 0;
    public ?int $user_id = null;
    public string $name = '';
    public string $color = '#3498db';
    public string $icon = 'fa-folder';
    public int $sort_order = 0;
    public int $is_deleted = 0;
    public string $created_at = '';
}
