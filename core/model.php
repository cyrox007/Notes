<?php
    class Model {
        /*
            Модель обычно включает методы выборки данных, это могут быть:
                > методы нативных библиотек pgsql или mysql;
                > методы библиотек, реализующих абстракицю данных. Например, методы библиотеки PEAR MDB2;
                > методы ORM;
                > методы для работы с NoSQL;
                > и др.
        */
        private $server_url, $name_db, $user_name, $password;

        // метод выборки данных
        public function connect_db($file_name_db) {
            $db = new SQLite3($file_name_db);
            return $db;
        }

        public function get_data($db, $table, $param, $value) {
            $sql = "SELECT * FROM {$table} WHERE {$param} = '{$value}'";
            return $result = $db->query($sql);
        }
        
    }