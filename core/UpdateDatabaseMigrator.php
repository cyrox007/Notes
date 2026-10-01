<?php

declare(strict_types=1);

namespace Core;

use mysqli;
use mysqli_result;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/MigrationManifest.php';
require_once __DIR__ . '/ModuleManifest.php';
require_once __DIR__ . '/DatabaseOwnership.php';
require_once __DIR__ . '/MigrationBaseline.php';

/**
 * Выполняет миграции БД без запуска отдельного PHP-процесса.
 *
 * Логика повторяет контракт bin/migrate.php: проверяет неизменность уже
 * применённых миграций, соблюдает канонический порядок и после выполнения
 * подтверждает фактический контракт схемы.
 */
final class UpdateDatabaseMigrator
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $resolved = realpath($root ?? dirname(__DIR__));
        if (!is_string($resolved) || !is_dir($resolved) || is_link($resolved)) {
            throw new RuntimeException('Не удалось безопасно определить корень приложения для миграций');
        }
        $this->root = rtrim($resolved, '/\\');
    }

    /**
     * @return array{stdout:string,pending:int,applied:int}
     */
    public function run(bool $statusOnly = false, ?int $baselineVersionCode = null): array
    {
        $canonicalManifest = new MigrationManifest($this->root);
        $canonical = $canonicalManifest->names();
        $ownership = DatabaseOwnership::fromPackageRoot($this->root);
        $manifest = $ownership->migrationNamesInCanonicalOrder($canonical);
        $currentTables = $ownership->tables();
        $packagedModules = $ownership->moduleIds();
        $hasFiles = in_array('files', $packagedModules, true);

        $db = $this->connect();
        try {
            $ledgerPresent = $this->migrationTableExists($db);
            $applied = $this->appliedMigrations($db);
            $this->verifyAppliedChecksums($canonicalManifest, $canonical, $applied);

            $baseline = [];
            if (!$ledgerPresent && is_int($baselineVersionCode) && $baselineVersionCode > 0) {
                $baseline = MigrationBaseline::appliedNames($canonical, $baselineVersionCode);
                foreach ($baseline as $filename) {
                    $applied[$filename] = hash('sha256', $canonicalManifest->readMigration($filename));
                }

                if (!$statusOnly && $baseline !== []) {
                    // Baseline фиксирует уже присутствующую схему опубликованной
                    // исходной версии. Полный целевой контракт проверяется только
                    // после применения действительно ожидающих миграций.
                    $this->ensureMigrationTable($db);
                    foreach ($baseline as $filename) {
                        $this->recordMigration(
                            $db,
                            $filename,
                            (string) $applied[$filename]
                        );
                    }
                    $ledgerPresent = true;
                }
            }

            $pending = [];
            foreach ($manifest as $filename) {
                if (!isset($applied[$filename])) {
                    $pending[] = $filename;
                }
            }

            if ($statusOnly) {
                $lines = [];
                foreach ($manifest as $filename) {
                    $lines[] = sprintf(
                        "%-8s %s",
                        in_array($filename, $pending, true) ? 'PENDING' : 'APPLIED',
                        $filename
                    );
                }
                $selectedApplied = count($manifest) - count($pending);
                $lines[] = sprintf('Summary: %d applied, %d pending', $selectedApplied, count($pending));
                if ($pending === []) {
                    $this->verifyCurrentContract($db, $currentTables, $hasFiles);
                    $lines[] = 'Schema contract: OK';
                }

                return [
                    'stdout' => implode(PHP_EOL, $lines) . PHP_EOL,
                    'pending' => count($pending),
                    'applied' => 0,
                ];
            }

            $this->ensureMigrationTable($db);
            $lines = [];
            if ($baseline !== []) {
                $lines[] = sprintf(
                    'Журнал миграций инициализирован из опубликованной версии %d: %d записей',
                    $baselineVersionCode,
                    count($baseline)
                );
            }
            foreach ($pending as $filename) {
                $sql = $canonicalManifest->readMigration($filename);
                $checksum = hash('sha256', $sql);
                $this->executeMigration($db, $sql);
                $this->recordMigration($db, $filename, $checksum);
                $lines[] = "Applying {$filename} ... OK";
            }

            $this->verifyCurrentContract($db, $currentTables, $hasFiles);
            $lines[] = $pending === []
                ? 'Database is up to date. Schema contract: OK'
                : 'Applied ' . count($pending) . ' migration(s). Schema contract: OK';

            return [
                'stdout' => implode(PHP_EOL, $lines) . PHP_EOL,
                'pending' => 0,
                'applied' => count($pending),
            ];
        } finally {
            $db->close();
        }
    }

    private function connect(): mysqli
    {
        $user = trim((string) (getenv('DBUSER') ?: ''));
        $database = trim((string) (getenv('DBNAME') ?: ''));
        if ($user === '' || $database === '') {
            throw new RuntimeException('Не заданы DBUSER/DBNAME для миграций обновления');
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

    /** @return array<string,string> */
    private function appliedMigrations(mysqli $db): array
    {
        if (!$this->migrationTableExists($db)) {
            return [];
        }

        $result = $db->query('SELECT migration,checksum FROM schema_migrations ORDER BY id ASC');
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[(string) $row['migration']] = strtolower((string) $row['checksum']);
        }
        $result->free();
        return $rows;
    }

    private function migrationTableExists(mysqli $db): bool
    {
        $result = $db->query(
            "SELECT 1 FROM information_schema.tables "
            . "WHERE table_schema=DATABASE() AND table_name='schema_migrations' LIMIT 1"
        );
        $exists = $result->num_rows === 1;
        $result->free();
        return $exists;
    }

    /**
     * @param list<string> $canonical
     * @param array<string,string> $applied
     */
    private function verifyAppliedChecksums(
        MigrationManifest $manifest,
        array $canonical,
        array $applied
    ): void {
        foreach ($applied as $filename => $checksum) {
            if (!in_array($filename, $canonical, true)) {
                throw new RuntimeException(
                    'Журнал миграций содержит файл вне канонического manifest: ' . $filename
                );
            }

            $expected = hash('sha256', $manifest->readMigration($filename));
            if (!hash_equals($expected, $checksum)) {
                throw new RuntimeException(
                    "Checksum уже применённой миграции {$filename} изменился"
                );
            }
        }
    }

    private function ensureMigrationTable(mysqli $db): void
    {
        $db->query(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(190) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_schema_migrations_name (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function executeMigration(mysqli $db, string $sql): void
    {
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($this->parseMigrationStatements($sql) as $statement) {
                $result = $db->query($statement);
                if ($result instanceof mysqli_result) {
                    $result->free();
                }
                $this->drainResults($db);
            }
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /** @return list<string> */
    private function parseMigrationStatements(string $sql): array
    {
        $delimiter = ';';
        $buffer = '';
        $statements = [];
        $lines = preg_split('/\R/u', $sql);
        if ($lines === false) {
            throw new RuntimeException('SQL миграции не является корректным UTF-8');
        }

        foreach ($lines as $line) {
            if (preg_match('/^\s*--/', $line) === 1) {
                continue;
            }
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
                if (trim($buffer) !== '') {
                    throw new RuntimeException('DELIMITER изменён до завершения SQL-выражения');
                }
                $delimiter = (string) $match[1];
                continue;
            }
            if (trim($line) === '' && trim($buffer) === '') {
                continue;
            }

            $buffer .= $line . "\n";
            $trimmed = rtrim($buffer);
            if ($trimmed === '' || !str_ends_with($trimmed, $delimiter)) {
                continue;
            }

            $statement = trim(substr($trimmed, 0, -strlen($delimiter)));
            $buffer = '';
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        if (trim($buffer) !== '') {
            throw new RuntimeException('SQL миграции содержит незавершённое выражение');
        }

        return $statements;
    }

    private function drainResults(mysqli $db): void
    {
        while ($db->more_results()) {
            $db->next_result();
            $result = $db->store_result();
            if ($result instanceof mysqli_result) {
                $result->free();
            }
        }
    }

    private function recordMigration(mysqli $db, string $filename, string $checksum): void
    {
        $stmt = $db->prepare('INSERT INTO schema_migrations (migration,checksum) VALUES (?,?)');
        $stmt->bind_param('ss', $filename, $checksum);
        $stmt->execute();
        $stmt->close();
    }

    private function tableExists(mysqli $db, string $table): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.tables '
            . 'WHERE table_schema=DATABASE() AND table_name=? LIMIT 1'
        );
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }

    /** @param list<string> $tables */
    private function verifyCurrentContract(mysqli $db, array $tables, bool $hasFiles): void
    {
        $missing = [];
        foreach ($tables as $table) {
            if (!$this->tableExists($db, $table)) {
                $missing[] = $table;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException(
                'Контракт БД неполон; отсутствуют таблицы: ' . implode(', ', $missing)
            );
        }

        if (!$this->tableExists($db, 'system_settings')) {
            throw new RuntimeException('Контракт БД неполон; отсутствует system_settings');
        }

        $result = $db->query(
            "SELECT setting_key,setting_value,setting_type,category,is_editable "
            . "FROM system_settings "
            . "WHERE setting_key IN ('installation_id','workspace_license_token')"
        );
        $settings = [];
        while ($row = $result->fetch_assoc()) {
            $settings[(string) $row['setting_key']] = $row;
        }
        $result->free();

        foreach (['installation_id', 'workspace_license_token'] as $key) {
            if (!isset($settings[$key])) {
                throw new RuntimeException("Контракт БД неполон; отсутствует настройка {$key}");
            }
            if ((string) $settings[$key]['setting_type'] !== 'string'
                || (string) $settings[$key]['category'] !== 'licensing'
                || (int) $settings[$key]['is_editable'] !== 0) {
                throw new RuntimeException("Некорректная metadata настройки лицензирования {$key}");
            }
        }

        $installationId = strtolower(trim((string) $settings['installation_id']['setting_value']));
        if (preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $installationId
        ) !== 1) {
            throw new RuntimeException('installation_id в БД не является UUID');
        }

        if (!$hasFiles) {
            return;
        }

        if (!$this->tableExists($db, 'user_storage_quotas')) {
            throw new RuntimeException('Контракт БД неполон; отсутствует user_storage_quotas');
        }

        $quota = $db->query(
            "SELECT setting_value,setting_type,category,is_editable "
            . "FROM system_settings "
            . "WHERE setting_key='file_manager_default_quota_bytes' LIMIT 1"
        )->fetch_assoc();
        if (!is_array($quota)
            || (string) $quota['setting_type'] !== 'integer'
            || (string) $quota['category'] !== 'file_manager'
            || (int) $quota['is_editable'] !== 1) {
            throw new RuntimeException('Настройка квоты File Manager отсутствует или повреждена');
        }
    }
}
