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
        public function get_data()
        {
            $this->server_url = getenv('DB_SERVER');
        }
    }