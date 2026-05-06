<?php
namespace App\Models;

use Core\ORM;

class UserToDialogsModel extends ORM {
    protected ?string $_tablename = 'user_to_dialogs';

    public int $id = 0;
    public int $dialog_id = 0;
    public int $user_id = 0;

    /* public function __construct() {
        $this->_tablename = "user_to_dialogs";
    } */
}