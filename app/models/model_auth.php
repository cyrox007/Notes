<?php
    class Model_Auth extends Model {
        public function get_data_password($login) {
            $db = $this->connect_db("base.db"); // коннектимся к базе
            $res = $this->get_data($db, "users", "username", $login);
            $row = $res->fetchArray(SQLITE3_ASSOC);
            
            $db->close();
            return $row["password"];
        }
    }