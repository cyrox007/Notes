<?php
namespace Core;

class Config {
    // БД
    //public $db_name = "wspace.db"; // передаем имя БД и даже относительный путь к ней относительно точки входа

    public static $db_connection;
    private static $configValues = [];
    
    public function __construct() {
        self::$db_connection = [
            'driver'       => getenv('DBDRIVER') ?: 'mysql',
            'hostname'     => getenv("DBHOST") ?: 'localhost',
            'port'         => getenv("DBPORT") ?: 3306,
            'username'     => getenv("DBUSER") ?: 'root',
            'password'     => getenv("DBPASS") ?: '',
            'database'     => getenv("DBNAME") ?: 'workspace'
        ];

        // Инициализация базовых значений конфигурации
        self::$configValues['SITEURL'] = ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    }

    /**
     * Получить значение конфигурации
     */
    public static function get(string $key, $default = null) {
        return self::$configValues[$key] ?? $default;
    }

    /**
     * Установить значение конфигурации
     */
    public static function set(string $key, $value): void {
        self::$configValues[$key] = $value;
    }

    // статичные параметры пользвателя
    /* 
        роль пользователя:
        001 - суперадмин
        111 - админ
        ...
        888 - пользователь 
        999 - гость
    */
    public $user_role_superadmin = 1;
    public $user_role_admin = 111;
    public $user_role_activate = 888; // роль активированного пользователя
    public $user_role_inactive = 899; // роль не активированного пользователя

    // хранит адрес сайта
    // вынести в отдельный класс хелпер
    public function base_url() {
        return ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/';
    }
}

new Config();