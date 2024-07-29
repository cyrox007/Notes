<?php
namespace Core;

class Config {
    // БД
    //public $db_name = "wspace.db"; // передаем имя БД и даже относительный путь к ней относительно точки входа

    public static $db_connection;
    
    public function __construct() {
        self::$db_connection = [
            'driver'       => getenv('DBDRIVER') ?: 'mysql',
            'hostname'     => getenv("DBHOST") ?: 'localhost',
            'port'         => getenv("DBPORT") ?: 3306,
            'username'     => getenv("DBUSER") ?: 'root',
            'password'     => getenv("DBPASS") ?: '',
            'database'     => getenv("DBNAME") ?: 'workspace'
        ];
    }
    
    // шифрование
    public $hash_key = "592e6419d1d04634848f40f22f9f71a7450800611f4e497cdd71b7cef3e3450ae63fd149609d36eb"; //SSL Key Code
    public $hash_method = "AES-192-CBC"; // алгоритм шифрования
    public $secret_key = '';

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
    
    // проверяет уровень доступа
    public function isAdmin($user, $admin) {
        if ($user > $admin)
            return false;
        
        return true;
    }

    // Данные сайта
    public $site = [
        'sitename' => 'My Workspace',
        'version' => "0.6.1",
        'base-url' => ''
    ];

    // Модуль Блокнот
    public $dir_notes = "c855721/"; // хранит папку с заметками

    // Модуль Мессенджер
    public $dir_messages = "q56Xl54Fs8zc/";
}

new Config();