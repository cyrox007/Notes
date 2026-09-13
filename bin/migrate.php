<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
}
if (class_exists(Dotenv\Dotenv::class) && is_file($root . '/.env')) {
    Dotenv\Dotenv::createUnsafeImmutable($root)->safeLoad();
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

$manifest = [
    '20260913_messenger_v2.sql',
    '20260913_user_contract_v2.sql',
    '20260913_messenger_membership_contract.sql',
    '20260913_messenger_attachments.sql',
    '20260913_messenger_delivery_receipts.sql',
    '20260913_messenger_reactions.sql',
    '20260913_messenger_saved_dialog.sql',
    '20260913_notes_private_attachments.sql',
    '20260913_user_fields_contract.sql',
    '20260913_tasks_contract.sql',
];

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
    $host = getenv('DBHOST') ?: 'localhost';
    $port = (int) (getenv('DBPORT') ?: 3306);
    $user = envRequired('DBUSER');
    $pass = (string) (getenv('DBPASS') ?: '');
    $name = envRequired('DBNAME');

    $db = new mysqli((string) $host, $user, $pass, $name, $port);
    $db->set_charset('utf8mb4');
    return $db;
}

function migrationTableExists(mysqli $db): bool
{
    $result = $db->query(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'schema_migrations' LIMIT 1"
    );
    return $result->num_rows === 1;
}

/** @return array<string,string> */
function appliedMigrations(mysqli $db): array
{
    if (!migrationTableExists($db)) {
        return [];
    }

    $result = $db->query('SELECT migration, checksum FROM schema_migrations ORDER BY id ASC');
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[(string) $row['migration']] = (string) $row['checksum'];
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

function readMigration(string $root, string $filename): array
{
    $path = $root . '/database/migrations/' . $filename;
    if (!is_file($path)) {
        throw new RuntimeException("Migration file not found: {$filename}");
    }
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read migration: {$filename}");
    }
    return [$path, $sql, hash('sha256', $sql)];
}

function executeMigration(mysqli $db, string $sql): void
{
    // DELIMITER is a mysql-client command, not SQL. Existing migrations use $$
    // only as procedure statement delimiters, so normalize them for multi_query.
    $normalized = preg_replace('/^\s*DELIMITER\s+\S+\s*;?\s*$/im', '', $sql) ?? $sql;
    $normalized = str_replace('$$', ';', $normalized);

    $db->query('SET FOREIGN_KEY_CHECKS=0');
    try {
        $db->multi_query($normalized);
        do {
            if ($result = $db->store_result()) {
                $result->free();
            }
        } while ($db->more_results() && $db->next_result());
    } finally {
        $db->query('SET FOREIGN_KEY_CHECKS=1');
    }
}

function recordMigration(mysqli $db, string $filename, string $checksum): void
{
    $stmt = $db->prepare(
        'INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)'
    );
    $stmt->bind_param('ss', $filename, $checksum);
    $stmt->execute();
    $stmt->close();
}

try {
    $db = dbConnection();
    $applied = appliedMigrations($db);
    $pending = [];

    foreach ($manifest as $filename) {
        [, , $checksum] = readMigration($root, $filename);
        if (isset($applied[$filename])) {
            if (!hash_equals($applied[$filename], $checksum)) {
                throw new RuntimeException(
                    "Checksum mismatch for already applied migration {$filename}. " .
                    'Do not edit applied migrations; create a new migration instead.'
                );
            }
            continue;
        }
        $pending[] = $filename;
    }

    if ($statusOnly || $dryRun) {
        foreach ($manifest as $filename) {
            $state = in_array($filename, $pending, true) ? 'PENDING' : 'APPLIED';
            echo sprintf("%-8s %s\n", $state, $filename);
        }
        echo sprintf("Summary: %d applied, %d pending\n", count($manifest) - count($pending), count($pending));
        $db->close();
        exit(0);
    }

    ensureMigrationTable($db);
    if ($pending === []) {
        echo "Database is up to date.\n";
        $db->close();
        exit(0);
    }

    foreach ($pending as $filename) {
        [, $sql, $checksum] = readMigration($root, $filename);
        echo "Applying {$filename} ... ";
        executeMigration($db, $sql);
        recordMigration($db, $filename, $checksum);
        echo "OK\n";
    }

    echo 'Applied ' . count($pending) . " migration(s).\n";
    $db->close();
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
