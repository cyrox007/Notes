<?php

namespace App\Models;

use Core\Model;

class MessageModel extends Model {
    protected $_tablename = "messages";

    public $id;
    public $from_user_id;
    public $dialog_id;
    public $message;
    public $created_at;
    public $updated_at;
    public $message_status;
}