<?php

declare(strict_types=1);

namespace Core;

class Config
{
    public static $db_connection;
    private static array $configValues = [];

    /**
     * Role numbers are identifiers, not an ordering. Authorization checks must
     * use the explicit allowlists below.
     */
    public const USER_ROLE_SUPERADMIN = 1;
    public const USER_ROLE_ADMIN = 111;
    public const USER_ROLE_USER = 888;
    public const USER_ROLE_INACTIVE = 899;
    public const USER_ROLE_BLOCKED = 999;

    public function __construct()
    {
        self::$db_connection = [
            'driver' => getenv('DBDRIVER') ?: 'mysql',
            'hostname' => getenv('DBHOST') ?: 'localhost',
            'port' => getenv('DBPORT') ?: 3306,
            'username' => getenv('DBUSER') ?: 'root',
            'password' => getenv('DBPASS') ?: '',
            'database' => getenv('DBNAME') ?: 'workspace',
        ];

        // HTTP workers, CLI migrations and Workerman all bootstrap Config. Prefer
        // the canonical configured origin; only infer from the request as a
        // development fallback when SITEURL is not present.
        $configuredSiteUrl = trim((string) (getenv('SITEURL') ?: ''));
        if ($configuredSiteUrl !== '') {
            self::$configValues['SITEURL'] = rtrim($configuredSiteUrl, '/');
        } else {
            $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
            $scheme = in_array($https, ['on', '1', 'true'], true) ? 'https' : 'http';
            $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
            self::$configValues['SITEURL'] = $scheme . '://' . $host;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$configValues[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$configValues[$key] = $value;
    }

    public static function isAdminRole(int $role): bool
    {
        return in_array($role, [self::USER_ROLE_SUPERADMIN, self::USER_ROLE_ADMIN], true);
    }

    public static function canAuthenticate(int $role): bool
    {
        return in_array(
            $role,
            [self::USER_ROLE_SUPERADMIN, self::USER_ROLE_ADMIN, self::USER_ROLE_USER],
            true
        );
    }

    // Backwards compatibility with older code paths.
    public $user_role_superadmin = self::USER_ROLE_SUPERADMIN;
    public $user_role_admin = self::USER_ROLE_ADMIN;
    public $user_role_activate = self::USER_ROLE_USER;
    public $user_role_inactive = self::USER_ROLE_INACTIVE;
    public $user_role_blocked = self::USER_ROLE_BLOCKED;

    public function base_url(): string
    {
        return rtrim((string) self::get('SITEURL', 'http://localhost'), '/') . '/';
    }
}

new Config();
