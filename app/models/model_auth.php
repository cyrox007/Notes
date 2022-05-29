<?php
    class Model_Auth extends Model {
        public function get_data_password($login) {
            $db = $this->connect_db("base.db"); // коннектимся к базе
            $res = $this->get_data($db, "users", "username", $login);
            $row = $res->fetchArray(SQLITE3_ASSOC);
            
            $db->close();
            return $row["password"];
        }

        public function get_data_invate_code($code) {
            $db = $this->connect_db("base.db"); // коннектимся к базе
            $res = $this->get_data($db, "invations", "invation_code", $code);
            if ($res == null)
                return null;
            
            $row = $res->fetchArray(SQLITE3_ASSOC);

            $db->close();
            return $row['user_id'];
        }
    }