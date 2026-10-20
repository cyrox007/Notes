<?php

declare(strict_types=1);

namespace Core;
require_once __DIR__ . '/PrefixMysqli.php';


require_once __DIR__ . '/Environment.php';

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
            $existingUserColumns = [];

            // Схему проверяем заново при запуске запроса, но обе группы
            // сведений получаем за одно чтение метаданных СУБД.
            $result = $db->query(
                'SELECT t.table_name, c.column_name '
                . 'FROM information_schema.tables t '
                . 'LEFT JOIN information_schema.columns c '
                . 'ON c.table_schema = t.table_schema '
                . 'AND c.table_name = t.table_name '
                . "AND t.table_name = 'users' "
                . 'WHERE t.table_schema = DATABASE()'
            );
            while ($row = $result->fetch_row()) {
                $table = $db->namespace->logical((string) ($row[0] ?? ''));
                $existingTables[$table] = true;
                if ($table === 'users' && isset($row[1])) {
                    $existingUserColumns[(string) $row[1]] = true;
                }
            }
            $result->free();

            $missingTables = [];
            foreach ($requiredTables as $table) {
                if (!isset($existingTables[$table])) {
                    $missingTables[] = $table;
                }
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
        $user = trim((string) (Environment::get('DBUSER') ?: ''));
        $database = trim((string) (Environment::get('DBNAME') ?: ''));
        if ($user === '' || $database === '') {
            throw new RuntimeException('Не заданы DBUSER/DBNAME для проверки схемы');
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new \Core\PrefixMysqli(
            (string) (Environment::get('DBHOST') ?: 'localhost'),
            $user,
            (string) (Environment::get('DBPASS') ?: ''),
            $database,
            (int) (Environment::get('DBPORT') ?: 3306)
        );
        $db->set_charset('utf8mb4');
        return $db;
    }
}
