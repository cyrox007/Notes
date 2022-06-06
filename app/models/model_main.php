<?php
    class Model_Main extends Model {
        public function __construct() {
            $this->config = new Config();
        }

        public function getUser_data($login) {
            $db = $this->connect_db($this->config->db_name);

            $all_info = [];

            $user_info = $this->get_data($db, "users", "username", $login);
            //$user_info_row = $user_info->fetchArray(SQLITE3_ASSOC);
            foreach ($user_info as $key => $value) {
                $all_info[$key] = $value;
            }
            $user_profile = $this->get_data($db, "profile", "user_id", $user_info['id']);
            //var_dump($user_profile);
            //$user_profile_row = $user_profile->fetchArray(SQLITE3_ASSOC);
            foreach ($user_profile as $key => $value) {
                if ($key == "id")
                    continue;
                
                $all_info[$key] = $value;
            }
            $db->close();
            return $all_info;
        }
    }