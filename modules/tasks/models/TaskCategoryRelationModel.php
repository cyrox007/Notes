<?php

namespace App\Models;

use Core\ORM;

class TaskCategoryRelationModel extends ORM {
    protected ?string $_tablename = "task_category_relations";

    public int $id = 0;
    public int $task_id = 0;
    public int $category_id = 0;
    public string $created_at = '';
}
