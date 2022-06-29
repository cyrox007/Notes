<?php
    class Model_Profile extends Model {
        public function __construct() {
            $this->config = new Config();
        }

        public function getUser_data($user_login) {
            $db = $this->connect_db($this->config->db_name); // соединение с базой

            $all_info = []; // инициализируем массив
            
            // получаем первую пачку данных о пользователе
            $user_info = $this->get_data($db, "users", "username", $user_login); 
            foreach ($user_info as $key => $value) { // перезапишем все в новый массив с ключем
                if ($key == "password") // поле пароля игнорируем
                    continue;
                
                $all_info[$key] = $value;
            }

            // получим вторую пачку данных о пользователе
            $user_profile = $this->get_data($db, "profile", "user_id", $user_info['id']);
            foreach ($user_profile as $key => $value) {
                if ($key == "id" || $key == "additional_information") // поле пароля и доп инфо игнорируем
                    continue;
                
                $all_info[$key] = $value;
            }
            $db->close(); // закроем БД
            return $all_info; // вернем наш массив
        }

        public function getPersonalNotes($user_id) {
            $db = $this->connect_db($this->config->db_name);

            $sql = "SELECT * FROM notes WHERE user_id = {$user_id}";
            $res = $db->query($sql);
            $arr = [];
            while ($row = $res->fetchArray()) {
                $arr[] = $row;
            }
            
            $db->close();
            return $arr;
        }

        public function update_user_profile($user_id, $array) {
            $db = $this->connect_db($this->config->db_name);

            $this->update_data($db, 'profile', 'user_id', $user_id, $array);
        }
    }