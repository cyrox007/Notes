<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/core/Environment.php';
require_once $root . '/core/MigrationManifest.php';
require_once $root . '/core/ModuleManifest.php';
require_once $root . '/core/DatabaseOwnership.php';
if (is_file($root . '/.env')) {
    \Core\Environment::load($root . '/.env');
}

$options = getopt('', ['dry-run', 'status', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/migrate.php [--dry-run|--status]\n";
    echo "  --dry-run  Show pending migrations without changing the database.\n";
    echo "  --status   Show applied/pending migrations and verify checksums.\n";
    exit(0);
}
$dryRun = isset($options['dry-run']);
$statusOnly = isset($options['status']);

$canonicalManifest = new \Core\MigrationManifest($root);
$canonical = $canonicalManifest->names();
$ownership = \Core\DatabaseOwnership::fromPackageRoot($root);
$manifest = $ownership->migrationNamesInCanonicalOrder($canonical);
$currentTables = $ownership->tables();
$packagedModules = $ownership->moduleIds();
$hasFiles = in_array('files', $packagedModules, true);

function envRequired(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException("Missing environment variable: {$name}");
    }
    return trim($value);
}

function dbConnection(): mysqli
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(
        (string) (getenv('DBHOST') ?: 'localhost'),
        envRequired('DBUSER'),
        (string) (getenv('DBPASS') ?: ''),
        envRequired('DBNAME'),
        (int) (getenv('DBPORT') ?: 3306)
    );
    $db->set_charset('utf8mb4');
    return $db;
}

function migrationTableExists(mysqli $db): bool
{
    $result = $db->query(
        "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='schema_migrations' LIMIT 1"
    );
    return $result->num_rows === 1;
}

/** @return array<string,string> */
function appliedMigrations(mysqli $db): array
{
    if (!migrationTableExists($db)) {
        return [];
    }
    $result = $db->query('SELECT migration,checksum FROM schema_migrations ORDER BY id ASC');
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[(string) $row['migration']] = strtolower((string) $row['checksum']);
    }
    return $rows;
}

function ensureMigrationTable(mysqli $db): void
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

/** @return list<string> */
function parseMigrationStatements(string $sql): array
{
    $delimiter = ';';
    $buffer = '';
    $statements = [];
    $lines = preg_split('/\R/u', $sql);
    if ($lines === false) {
        throw new RuntimeException('Malformed migration: SQL is not valid UTF-8');
    }
    foreach ($lines as $line) {
        if (preg_match('/^\s*--/', $line) === 1) {
            continue;
        }
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
            if (trim($buffer) !== '') {
                throw new RuntimeException('Malformed migration: DELIMITER changed before statement ended');
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
        throw new RuntimeException('Malformed migration: unterminated SQL statement');
    }
    return $statements;
}

function drainResults(mysqli $db): void
{
    while ($db->more_results()) {
        $db->next_result();
        if ($result = $db->store_result()) {
            $result->free();
        }
    }
}

function executeMigration(mysqli $db, string $sql): void
{
    $db->query('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach (parseMigrationStatements($sql) as $statement) {
            $result = $db->query($statement);
            if ($result instanceof mysqli_result) {
                $result->free();
            }
            drainResults($db);
        }
    } finally {
        $db->query('SET FOREIGN_KEY_CHECKS=1');
    }
}

function recordMigration(mysqli $db, string $filename, string $checksum): void
{
    $stmt = $db->prepare('INSERT INTO schema_migrations (migration,checksum) VALUES (?,?)');
    $stmt->bind_param('ss', $filename, $checksum);
    $stmt->execute();
    $stmt->close();
}

function tableExists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $exists;
}

/** @param list<string> $tables */
function verifyCurrentContract(mysqli $db, array $tables, bool $hasFiles): void
{
    $missing = [];
    foreach ($tables as $table) {
        if (!tableExists($db, $table)) {
            $missing[] = $table;
        }
    }
    if ($missing !== []) {
        throw new RuntimeException('Database contract is incomplete; missing tables: ' . implode(', ', $missing));
    }

    if (!tableExists($db, 'system_settings')) {
        throw new RuntimeException('Database contract is incomplete; missing table system_settings');
    }
    $result = $db->query(
        "SELECT setting_key,setting_value,setting_type,category,is_editable
         FROM system_settings WHERE setting_key IN ('installation_id','workspace_license_token')"
    );
    $settings = [];
    while ($row = $result->fetch_assoc()) {
        $settings[(string) $row['setting_key']] = $row;
    }
    foreach (['installation_id', 'workspace_license_token'] as $key) {
        if (!isset($settings[$key])) {
            throw new RuntimeException("Database contract is incomplete; missing {$key} setting");
        }
        if ((string) $settings[$key]['setting_type'] !== 'string'
            || (string) $settings[$key]['category'] !== 'licensing'
            || (int) $settings[$key]['is_editable'] !== 0) {
            throw new RuntimeException("Incompatible licensing setting metadata: {$key}");
        }
    }
    $installationId = strtolower(trim((string) $settings['installation_id']['setting_value']));
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $installationId) !== 1) {
        throw new RuntimeException('Database contract is incomplete; installation_id is not a UUID');
    }

    if ($hasFiles) {
        if (!tableExists($db, 'user_storage_quotas')) {
            throw new RuntimeException('Database contract is incomplete; missing table user_storage_quotas');
        }
        $quota = $db->query(
            "SELECT setting_value,setting_type,category,is_editable FROM system_settings
             WHERE setting_key='file_manager_default_quota_bytes' LIMIT 1"
        )->fetch_assoc();
        if (!is_array($quota)
            || (string) $quota['setting_type'] !== 'integer'
            || (string) $quota['category'] !== 'file_manager'
            || (int) $quota['is_editable'] !== 1) {
            throw new RuntimeException('Database contract is incomplete; File Manager quota setting is missing or invalid');
        }
    }
}

try {
    $db = dbConnection();
    $applied = appliedMigrations($db);

    // Every ledger entry remains verifiable against the immutable canonical set,
    // even when its owning module is absent from this packaged composition.
    foreach ($applied as $filename => $checksum) {
        if (!in_array($filename, $canonical, true)) {
            throw new RuntimeException('Migration ledger contains entry absent from canonical manifest: ' . $filename);
        }
        $expected = hash('sha256', $canonicalManifest->readMigration($filename));
        if (!hash_equals($expected, $checksum)) {
            throw new RuntimeException(
                "Checksum mismatch for already applied migration {$filename}. Do not edit applied migrations; create a new migration instead."
            );
        }
    }

    $pending = [];
    foreach ($manifest as $filename) {
        if (!isset($applied[$filename])) {
            $pending[] = $filename;
        }
    }

    if ($statusOnly || $dryRun) {
        foreach ($manifest as $filename) {
            echo sprintf("%-8s %s\n", in_array($filename, $pending, true) ? 'PENDING' : 'APPLIED', $filename);
        }
        $selectedApplied = count($manifest) - count($pending);
        echo sprintf("Summary: %d applied, %d pending\n", $selectedApplied, count($pending));
        if ($statusOnly && $pending === []) {
            verifyCurrentContract($db, $currentTables, $hasFiles);
            echo "Schema contract: OK\n";
        }
        $db->close();
        exit(0);
    }

    ensureMigrationTable($db);
    foreach ($pending as $filename) {
        $sql = $canonicalManifest->readMigration($filename);
        $checksum = hash('sha256', $sql);
        echo "Applying {$filename} ... ";
        executeMigration($db, $sql);
        recordMigration($db, $filename, $checksum);
        echo "OK\n";
    }

    verifyCurrentContract($db, $currentTables, $hasFiles);
    echo $pending === []
        ? "Database is up to date. Schema contract: OK\n"
        : 'Applied ' . count($pending) . " migration(s). Schema contract: OK\n";
    $db->close();
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
