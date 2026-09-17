<?php

namespace App\Models;

use Core\ORM;

class TaskReminderModel extends ORM {
    protected ?string $_tablename = "task_reminders";

    public int $id = 0;
    public int $task_id = 0;
    public string $reminder_time = '';
    public int $is_sent = 0;
    public ?string $sent_at = null;
    public string $created_at = '';
}
