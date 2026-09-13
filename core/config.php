<?php
namespace Core;

class Config {
    // БД
    //public $db_name = "wspace.db"; // передаем имя БД и даже относительный путь к ней относительно точки входа

    public static $db_connection;
    private static $configValues = [];

    /**
     * Роли пользователя.
     *
     * Важно: порядок чисел больше не используется как признак привилегий.
     * Проверки должны выполняться только через isAdminRole()/canAuthenticate().
     */
    public const USER_ROLE_SUPERADMIN = 1;
    public const USER_ROLE_ADMIN = 111;
    public const USER_ROLE_USER = 888;
    public const USER_ROLE_INACTIVE = 899;
    public const USER_ROLE_BLOCKED = 999;
    
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

    /**
     * Административная роль определяется allowlist-ом, а не сравнением чисел.
     */
    public static function isAdminRole(int $role): bool {
        return in_array($role, [self::USER_ROLE_SUPERADMIN, self::USER_ROLE_ADMIN], true);
    }

    /**
     * Вход разрешён только явно активным ролям. Неизвестные, inactive и blocked
     * значения по умолчанию не получают доступ.
     */
    public static function canAuthenticate(int $role): bool {
        return in_array(
            $role,
            [self::USER_ROLE_SUPERADMIN, self::USER_ROLE_ADMIN, self::USER_ROLE_USER],
            true
        );
    }

    // Обратная совместимость со старым API конфигурации.
    public $user_role_superadmin = self::USER_ROLE_SUPERADMIN;
    public $user_role_admin = self::USER_ROLE_ADMIN;
    public $user_role_activate = self::USER_ROLE_USER;
    public $user_role_inactive = self::USER_ROLE_INACTIVE;
    public $user_role_blocked = self::USER_ROLE_BLOCKED;

    // хранит адрес сайта
    // вынести в отдельный класс хелпер
    public function base_url() {
        return ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/';
    }
}

new Config();