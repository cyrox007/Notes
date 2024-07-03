<?php
namespace App\Models;

use Core\Model;

class UserToDialogsModel extends Model {
    protected $_tablename;

    public $id;
    public string $dialog_id;
    public string $user_id;

    public function __construct() {
        $this->_tablename = "user_to_dialogs";
    }
}