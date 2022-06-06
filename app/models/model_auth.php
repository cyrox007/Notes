<?php
    class Model_Auth extends Model {
        public function __construct() {
            $this->config = new Config();
        }

        public function get_data_password($login) {
            $db = $this->connect_db("base.db"); // коннектимся к базе
            $res = $this->get_data($db, "users", "username", $login);
            //$row = $res->fetchArray(SQLITE3_ASSOC);
            
            $db->close();
            return $res["password"];
        }

        public function get_data_invate_code($code) {
            $db = $this->connect_db("base.db"); // коннектимся к базе
            $res = $this->get_data($db, "invations", "invation_code", $code);
            if ($res == null)
                return null;
            
            //$row = $res->fetchArray(SQLITE3_ASSOC);

            $db->close();
            return $res['user_id'];
        }

        public function update_user_profile_in_register($id, $arr1 = [], $arr2 = []) {
            $db = $this->connect_db($this->config->db_name); // коннектимся к базе
            
            /* временное решение
            foreach ($arr1 as $key => $value) {
                $db->query("UPDATE users SET {$key}='{$value}' WHERE id = {$id}");
            } 
            foreach ($arr2 as $key => $value) {
                $db->query("UPDATE profile SET {$key}='{$value}' WHERE user_id = {$id}");
            } */

            $this->update_data($db, "users", "id", $id, $arr1);
            $this->update_data($db, "profile", "user_id", $id, $arr2);
            $db->close();
            return true;
        }
        public function delete_invate($invite_id) {
            $db = $this->connect_db($this->config->db_name); // коннектимся к базе
            $this->delete_data($db, "invations", "user_id", $invite_id);
        }
    }