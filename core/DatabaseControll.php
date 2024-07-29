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
        $dsn = $this->getDsn($config);
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        return new PDO($dsn, $config['username'], $config['password'], $options);
    }

    private function getDsn(array $config): string {
        switch ($config['driver']) {
            case 'mysql':
                return "mysql:host={$config['hostname']};port={$config['port']};dbname={$config['database']}";
            case 'mongodb':
                return "mongodb://{$config['hostname']}:{$config['port']}";
            case 'sqlite':
                return "sqlite:{$config['database']}";
            case 'postgresql':
                return "pgsql:host={$config['hostname']};port={$config['port']};dbname={$config['database']}";
            default:
                throw new \Exception('Unsupported database driver');
        }
    }
}