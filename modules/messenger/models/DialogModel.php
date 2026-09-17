<?php

declare(strict_types=1);

namespace App\Models;

use Core\ORM;

class DialogModel extends ORM
{
    protected ?string $_tablename = 'dialogs';

    public int $id = 0;
    public string $uid = '';
    public string $type = 'private';
    public ?string $name = null;
    public ?string $avatar = null;
    public ?int $created_by = null;
    public string $created_at = '';
    public string $updated_at = '';
}
