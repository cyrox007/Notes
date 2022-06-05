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

        // метод соединения с БД
        public function connect_db($file_name_db) {
            $db = new SQLite3($file_name_db);
            return $db;
        }
        
        // метод для получения поля таблицы. 
        // Принимает параметры: Открытая БД, таблицу откуда получаем, параметр поиска, значение поиска
        public function get_data($db, $table, $param, $value) {
            $sql = "SELECT * FROM {$table} WHERE {$param} = '{$value}'";
            return $result = $db->query($sql);
        }
        
        // Обновление поля таблицы:
        // Принимает параметры: Открытая БД, таблица, параметр поиска, 
        // значение поиска, массив значений, который будет внесен
        // key => value
        public function update_data($db, $table, $where_param, $where_value, $array) {
            $imploded = []; // это строка в которую будем собирать параметры для изменения и значения
            foreach ($array as $key => $value) { // разбираем массив
                $imploded[] = "$key = '$value'";
            }
            
            $string_parametrs = implode(", ", $imploded);
            $string_parametrs = str_replace('"', '', $string_parametrs); // почистим от кавычек
            $sql = "UPDATE {$table} SET {$string_parametrs} WHERE {$where_param} = {$where_value}";
            $db->query($sql);
        }

        // функция удаления позиции из БД
        public function delete_data($db, $table, $where_param, $where_value) {
            $sql = "DELETE FROM {$table} WHERE {$where_param} = {$where_value}";
            $db->query($sql);
        }
    }