<?php
class Model_Messager extends Model {
    function __construct() {
        $this->config = new Config();
    }

    function getUser_data($login) {
        $db = $this->connect_db($this->config->db_name);

        $all_info = [];

        $user_info = $this->get_data($db, "users", "username", $login);
        foreach ($user_info as $key => $value) {
            $all_info[$key] = $value;
        }

        $user_profile = $this->get_data($db, "profile", "user_id", $user_info['id']);
        foreach ($user_profile as $key => $value) {
            if ($key == "id")
                continue;
            
            $all_info[$key] = $value;
        }
        $db->close();
        return $all_info;
    }

    function getUserDialogues($user_id) {
        $db = $this->connect_db($this->config->db_name);

        $sql = "SELECT d.id, d.hash, d.public, d.user_id, 
                    ud.user_id, ud.dialog_id, 
                    u.first_name, u.surname
                FROM dialoges AS d
                INNER JOIN user_to_dialog AS ud
                    ON ud.dialog_id = d.id
                INNER JOIN profile AS u 
                    ON ud.user_id = u.user_id 
                WHERE d.user_id = {$user_id}";

        $raw = $db->query($sql);
        
        $result = [];
        while ($row = $raw->fetchArray()) {
            $result[] = $row;
        }

        $db->close();
        return $result;
    }

    public function get_messages($dialog_id) {
        $db = $this->connect_db($this->config->db_name);

        $sql = "SELECT msg.id, msg.message, u.first_name, u.surname 
                FROM messages AS msg
                INNER JOIN profile AS u
                    ON msg.sender_id = u.user_id
                WHERE dialog_id = {$dialog_id}";

        $raw = $db->query($sql);
        $result = [];
        while ($row = $raw->fetchArray()) {
            $result[] = $row;
        }

        $db->close();
        return $result;
    }

    function getAllUsers($user_id) {
        $db = $this->connect_db($this->config->db_name);

        $sql = "SELECT * FROM profile WHERE user_id != {$user_id}";
        $res = $db->query($sql);
        $arr = [];
        while ($row = $res->fetchArray()) {
            $arr[] = $row;
        }
        
        $db->close();
        return $arr;
    }

    function addDialog($user_id, $interlocutor_id, $file_dialog) {
        $db = $this->connect_db($this->config->db_name);
        
        $array = [
            'file_messages' => $file_dialog,
            'interlocutor' => $interlocutor_id,
            'user_id' => $user_id
        ];

        $this->insert_data($db, 'messages', $array);
    }

    function getInfoAbouteInterlocutor($user_id) {
        $db = $this->connect_db($this->config->db_name);

        $all_info = [];

        $user_profile = $this->get_data($db, "profile", "user_id", $user_id);
        
        foreach ($user_profile as $key => $value) {
            if ($key == "first_name" || $key == "surname")
                $all_info[$key] = $value;
            
            continue;
        }
        
        $db->close();
        return $all_info;
    }
}