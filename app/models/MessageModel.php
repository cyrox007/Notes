<?php

namespace App\Models;

use Core\ORM;

class MessageModel extends ORM {
    protected ?string $_tablename = "messages";

    public int $id = 0;
    public ?string $uid = null;
    public int $from_user_id = 0;
    public int $dialog_id = 0;
    public ?string $message = '';
    public ?string $created_at = '';
    public ?string $updated_at = '';
    public ?string $message_status = '';
}