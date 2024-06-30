<?php
namespace App\Models;
use Core\Model;


class FieldModel extends Model {
    protected $_tablename = "user_fields";

    public $id;
    public $field_name;
    public $field_type;
    public $field_label;
    public $is_required;
    public $created_at;
    public $updated_at;
}