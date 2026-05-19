<?php

namespace App\Models;

use Core\ORM;

class MessageModel extends ORM {
    protected ?string $_tablename = "messages";

    public int $id = 0;
    public ?string $uid = null;
    public int $dialog_id = 0;
    public int $sender_id = 0;
    public ?string $content = '';
    public ?string $content_type = 'text';
    public ?string $meta_data = null;
    public ?string $message_status = 'sent';
    public int $is_deleted = 0;
    public ?string $edited_at = null;
    public ?string $deleted_at = null;
    public ?string $created_at = '';
    public ?string $updated_at = '';
}