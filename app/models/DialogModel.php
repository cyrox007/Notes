<?php
namespace App\Models;

use Core\ORM;

class DialogModel extends ORM {
    protected ?string $_tablename  = "dialogs";

    public int $id = 0;
    public ?string $uid = '';
    public ?string $dialogname = '';
    public ?string $created_at = '';
    public ?string $updated_at = '';

    /* public $config;

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

        $sql = "SELECT utd.id, utd.user_id, 
                    utd.dialog_id, d.hash
                FROM user_to_dialog AS utd
                INNER JOIN dialoges AS d
                    ON utd.dialog_id = d.id
                WHERE utd.user_id = {$user_id}";

        $raw = $db->query($sql);
        
        $result = [];
        while ($row = $raw->fetchArray()) {
            $result[] = $row;
        }
        
        if ($result) {
            $new_res = [];
            foreach ($result as $value) {
                $sql = "SELECT p.first_name, p.surname, p.user_id
                        FROM user_to_dialog AS utd
                        INNER JOIN profile AS p
                            ON p.user_id = utd.user_id
                        WHERE utd.dialog_id = {$value['dialog_id']} AND utd.user_id != {$user_id}";
                $res = $db->query($sql);
                $value['profile'] = $res->fetchArray(SQLITE3_ASSOC);
                $new_res[] = $value;
            }
        }
        $db->close();
        return $new_res;
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

    function getUsers($user_id) {
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

    /*  Добавляет новый диалог в БД и создает связи с ним
        принимает id пользователей учавствующих в диалоге 
        и имя диалога */
    /* function addDialog($data) {
        $db = $this->connect_db($this->config->db_name);
        $chatN = $data['chat_name'] ? $data['chat_name'] : null;
        $hash = null;
        $sql = "INSERT INTO dialoges (hash, dialog_name) VALUES ('{$hash}', '{$chatN}')";
        $db->query($sql);
        $res = $db->query("SELECT last_insert_rowid()");
        $row = $res->fetchArray(SQLITE3_ASSOC);

        $sql2 = "INSERT INTO user_to_dialog (user_id, dialog_id) VALUES ({$data['user-id']}, {$row['last_insert_rowid()']})";
        $db->query($sql2);
        foreach ($data['interlocutor_ids'] as $value) {
            $addsql = "INSERT INTO user_to_dialog (user_id, dialog_id) VALUES ({$value}, {$row['last_insert_rowid()']})";
            $db->query($addsql);
        }
        $db->close();
    } */
}