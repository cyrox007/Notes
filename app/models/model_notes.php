<?php
    class Model_Notes extends Model {
        public function __construct() {
            $this->config = new Config();
        }

        public function getUser_data($login) {
            $db = $this->connect_db($this->config->db_name);

            $all_info = [];

            $user_info = $this->get_data($db, "users", "username", $login);
            $user_info_row = $user_info->fetchArray(SQLITE3_ASSOC);
            foreach ($user_info_row as $key => $value) {
                $all_info[$key] = $value;
            }
            $user_profile = $this->get_data($db, "profile", "user_id", $user_info_row['id']);
            $user_profile_row = $user_profile->fetchArray(SQLITE3_ASSOC);
            foreach ($user_profile_row as $key => $value) {
                $all_info[$key] = $value;
            }
            return $all_info;
        }
    }