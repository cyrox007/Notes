<?php

namespace Core;

use PDO;
use Core\Config;

class DatabaseControll {
    public static function connect(): PDO {
        $instance = new self();
        return $instance->connectDb();
    }

    private function connectDb(): PDO {
        $config = Config::$db_connection;
        $dsn = "mysql:host={$config['hostname']};port={$config['port']};dbname={$config['database']}";
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        return new PDO($dsn, $config['username'], $config['password'], $options);
    }

}