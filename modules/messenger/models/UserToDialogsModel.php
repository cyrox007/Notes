<?php

declare(strict_types=1);

namespace App\Models;

use Core\ORM;

class UserToDialogsModel extends ORM
{
    protected ?string $_tablename = 'user_to_dialogs';

    public int $id = 0;
    public int $dialog_id = 0;
    public int $user_id = 0;
    public string $role = 'member';
    public string $joined_at = '';
    public ?int $last_delivered_message_id = null;
    public ?int $last_read_message_id = null;
    public int $is_deleted = 0;
    public ?string $archived_at = null;
    public ?string $muted_until = null;
    public ?string $pinned_at = null;
}
