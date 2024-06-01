<?php
namespace App\Models;

use Core\Model;
use Core\Config;

class Model_Auth extends Model {
    private $config;
    public function __construct() {
        $this->config = new Config();
    }

    public function get_data_password($login) {
        $db = $this->connect_db(); // коннектимся к базе
        $res = $this->get_data($db, "users", "username", $login);
        
        return $res["password"];
    }

    public function get_data_invate_code($code) {
        $db = $this->connect_db(); // коннектимся к базе
        $res = $this->get_data($db, "invations", "invation_code", $code);
        if ($res == null)
            return null;
        
        return $res['user_id'];
    }

    public function update_user_profile_in_register($id, $arr1 = [], $arr2 = []) {
        $db = $this->connect_db(); // коннектимся к базе

        $this->update_data($db, "users", "id", $id, $arr1);
        $this->update_data($db, "profile", "user_id", $id, $arr2);

        return true;
    }
    public function delete_invate($invite_id) {
        $db = $this->connect_db(); // коннектимся к базе
        $this->delete_data($db, "invations", "user_id", $invite_id);
    }
}