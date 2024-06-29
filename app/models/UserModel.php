<?php
namespace App\Models;

use Core\Model;
use Core\Config;

class UserModel extends Model {
    protected static $_tablename = "users";
    
    public $id;
    public $uid;
    public string $username;
    public string $email;
    public string $password;
    public string $firstname;
    public string $surname;
    public string $patronymic;
    public string $phone;
    public string $property;
    public int $role;
    public string $reg_date;
    public string $service_token;
    public string $user_status;
    public string $user_image;
    public int $socket_connection_id;

    public function get_user_by_login(string $login) {
        return $this->select('users')
        ->where('username', '=', $login)
        ->first();
    }

    /* public function get_data_password($login) {
        //$db = $this->connect_db(); // коннектимся к базе
        //$res = $this->get_data($db, "users", "username", $login);
        
        //return $res["password"];
    }

    public function get_data_invate_code($code) {
        //$db = $this->connect_db(); // коннектимся к базе
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
    } */
}