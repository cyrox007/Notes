<?php
namespace App\Models;

use Core\ORM;

class DialogModel extends ORM {
    protected ?string $_tablename  = "dialogs";

    public int $id = 0;
    public ?string $uid = '';
    public ?string $type = 'private';
    public ?string $name = '';
    public int $created_by = 0;
    public ?string $created_at = '';
    public ?string $updated_at = '';
}