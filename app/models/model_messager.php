<?php
class Model_Messager extends Model {
    function __construct() {
        $this->config = new Config();
    }

    function getUser_data($login) {
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
    function getUserDialogues($user_id) {
        $db = $this->connect_db($this->config->db_name);
        
        $dialogues_list = $this->get_data($db, "messages", "user_id", $user_id);
        $dialogues_list_2 = $this->get_data($db, "messages", "interlocutor", $user_id);
        
        if ($dialogues_list != false && $dialogues_list_2 != false)
            $output = array_merge($dialogues_list, $dialogues_list_2);

        if (!$dialogues_list) {
            $dialogues_list = $dialogues_list_2;
        }
        
        return $dialogues_list;
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
}