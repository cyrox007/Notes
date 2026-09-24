<?php

declare(strict_types=1);

namespace Core;

use mysqli;
use RuntimeException;

/**
 * Ранняя проверка совместимости схемы БД с распакованным кодом.
 *
 * Используется до загрузки модулей, чтобы ручная замена файлов новой версии
 * поверх старой установки не превращалась в цепочку случайных HTTP 500.
 * Проверка смотрит на фактическую схему, а не на schema_migrations: новая
 * установка может быть полностью актуальной без журнала миграций.
 */
final class SchemaReadiness
{
    /** @var list<string> */
    private const REQUIRED_USER_COLUMNS = [
        'uid',
        'password_hash',
        'lastname',
        'avatar',
        'is_active',
        'account_status',
        'totp_enabled',
        'totp_secret',
        'totp_last_counter',
        'totp_recovery_codes',
        'totp_confirmed_at',
    ];

    /**
     * @return array{ready:bool,missing_tables:list<string>,missing_user_columns:list<string>}
     */
    public static function inspect(string $root): array
    {
        $ownership = DatabaseOwnership::fromPackageRoot($root);
        $requiredTables = $ownership->tables();

        $db = self::connect();
        try {
            $existingTables = [];
            $result = $db->query(
                'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
            );
            while ($row = $result->fetch_row()) {
                $existingTables[(string) ($row[0] ?? '')] = true;
            }
            $result->free();

            $missingTables = [];
            foreach ($requiredTables as $table) {
                if (!isset($existingTables[$table])) {
                    $missingTables[] = $table;
                }
            }

            $existingUserColumns = [];
            if (isset($existingTables['users'])) {
                $columns = $db->query(
                    "SELECT column_name FROM information_schema.columns "
                    . "WHERE table_schema = DATABASE() AND table_name = 'users'"
                );
                while ($row = $columns->fetch_row()) {
                    $existingUserColumns[(string) ($row[0] ?? '')] = true;
                }
                $columns->free();
            }

            $missingUserColumns = [];
            foreach (self::REQUIRED_USER_COLUMNS as $column) {
                if (!isset($existingUserColumns[$column])) {
                    $missingUserColumns[] = $column;
                }
            }

            sort($missingTables, SORT_STRING);
            sort($missingUserColumns, SORT_STRING);

            return [
                'ready' => $missingTables === [] && $missingUserColumns === [],
                'missing_tables' => $missingTables,
                'missing_user_columns' => $missingUserColumns,
            ];
        } finally {
            $db->close();
        }
    }

    private static function connect(): mysqli
    {
        $user = trim((string) (getenv('DBUSER') ?: ''));
        $database = trim((string) (getenv('DBNAME') ?: ''));
        if ($user === '' || $database === '') {
            throw new RuntimeException('Не заданы DBUSER/DBNAME для проверки схемы');
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new mysqli(
            (string) (getenv('DBHOST') ?: 'localhost'),
            $user,
            (string) (getenv('DBPASS') ?: ''),
            $database,
            (int) (getenv('DBPORT') ?: 3306)
        );
        $db->set_charset('utf8mb4');
        return $db;
    }
}
