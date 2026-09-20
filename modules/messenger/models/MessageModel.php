<?php

declare(strict_types=1);

namespace App\Models;

use Core\ORM;

class MessageModel extends ORM
{
    protected ?string $_tablename = 'messages';

    public int $id = 0;
    public string $uid = '';
    public int $dialog_id = 0;
    public int $from_user_id = 0;
    public ?int $reply_to_message_id = null;
    public string $message = '';
    public string $message_type = 'text';
    public ?string $media_url = null;
    public mixed $meta_data = null;
    public string $message_status = 'sent';
    public int $is_deleted = 0;
    public ?string $edited_at = null;
    public ?string $deleted_at = null;
    public string $created_at = '';
    public string $updated_at = '';
}
