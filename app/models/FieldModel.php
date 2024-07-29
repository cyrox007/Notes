<?php
namespace App\Models;
use Core\ORM;


class FieldModel extends ORM {
    protected ?string $_tablename = "user_fields";

    public int $id = 0;
    public string $field_name = '';
    public string $field_type = '';
    public string $field_label = '';
    public bool $is_required = false;
    public ?string $created_at = '';
    public ?string $updated_at = '';
}