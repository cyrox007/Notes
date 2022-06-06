<?php
    class Model_Notes extends Model {
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
            //$user_profile_row = $user_profile->fetchArray(SQLITE3_ASSOC);
            foreach ($user_profile as $key => $value) {
                if ($key == "id")
                    continue;
                
                $all_info[$key] = $value;
            }
            $db->close();
            return $all_info;
        }

        public function createNewNote($nameNote, $filePath, $author, $author_id) {
            $db = $this->connect_db($this->config->db_name); // connect DB

            $date = date("Y-m-d H:i:s");

            $array_data = [
                'name_note' => $nameNote,
                'date_create' => $date,
                'date_edit' => $date,
                'notefile_link' => $filePath,
                'author' => $author,
                'user_id' => $author_id
            ];
            
            $this->insert_data($db, "notes", $array_data);

            $res = $this->get_data($db, "notes", "notefile_link", $filePath);
            $db->close();
            return $res['id'];
        }

        public function getAllNotes() {
            $db = $this->connect_db($this->config->db_name);

            $sql = "SELECT * FROM notes";
            $res = $db->query($sql);
            $arr = [];
            while ($row = $res->fetchArray()) {
                $arr[] = $row;
            }
            
            $db->close();
            return $arr;
        }

        public function getNote_data($note_id) {
            $db = $this->connect_db($this->config->db_name);

            $note = $this->get_data($db, "notes", "id", $note_id);

            return $note;
        }

        public function update_note($id, $datetime) {
            $db = $this->connect_db($this->config->db_name);

            $arr1 = [
                'date_edit' => $datetime
            ];

            $this->update_data($db, "notes", "id", $id, $arr1);
        }
    }