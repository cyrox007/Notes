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
        
        $dialogues_list = $this->get_data($db, "conversation", "user_id", $user_id); // диалоги со мной
        $dialogues_list_2 = $this->get_data($db, "conversation", "interlocutor_id", $user_id); // мои диалоги к кем то

        $user_info = $this->get_data($db, "profile", "user_id", $dialogues_list['interlocutor']); // получим инфо собеседника
        $dialogues_list['first_name'] = $user_info['first_name'];
        $dialogues_list['surname'] = $user_info['surname'];
        
        
        if ($dialogues_list != false && $dialogues_list_2 != false) // если и со мной есть диалоги и мои объединяем список
            return $output = array_merge($dialogues_list, $dialogues_list_2);

        if (!$dialogues_list) { // если диалогов со мной нет то 
            return $dialogues_list_2;
        } 
        if (!$dialogues_list_2) {
            return $dialogues_list;
        }

        
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