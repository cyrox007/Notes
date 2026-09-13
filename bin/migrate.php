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
    '20260913_system_settings_storage_quota.sql',
];

$currentTables = [
    'users', 'dialogs', 'user_to_dialogs', 'messages', 'message_user_deletions',
    'messenger_attachments', 'message_reactions',
    'notes', 'note_attachments', 'shared_notes', 'note_history', 'note_tags', 'note_tag_relations',
    'user_files', 'user_fields',
    'tasks', 'subtasks', 'task_categories', 'task_category_relations', 'task_reminders',
    'system_settings', 'user_storage_quotas',
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

/** @return array{0:string,1:string,2:string} */
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

/** @return list<string> */
function parseMigrationStatements(string $sql): array
{
    $delimiter = ';';
    $buffer = '';
    $statements = [];
    $lines = preg_split('/\R/', $sql) ?: [];

    foreach ($lines as $line) {
        if (preg_match('/^\s*--/', $line) === 1) {
            continue;
        }

        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
            if (trim($buffer) !== '') {
                throw new RuntimeException('Malformed migration: DELIMITER changed before statement ended');
            }
            $delimiter = $match[1];
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
    $statements = parseMigrationStatements($sql);
    $db->query('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($statements as $statement) {
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
    $stmt = $db->prepare('INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)');
    $stmt->bind_param('ss', $filename, $checksum);
    $stmt->execute();
    $stmt->close();
}

/** @param list<string> $tables */
function verifyCurrentContract(mysqli $db, array $tables): void
{
    $escaped = array_map(static fn (string $table): string => "'" . $db->real_escape_string($table) . "'", $tables);
    $result = $db->query(
        'SELECT TABLE_NAME AS contract_table FROM information_schema.tables ' .
        'WHERE table_schema = DATABASE() AND table_name IN (' . implode(',', $escaped) . ')'
    );
    $existing = [];
    while ($row = $result->fetch_assoc()) {
        $existing[] = (string) $row['contract_table'];
    }
    $missing = array_values(array_diff($tables, $existing));
    if ($missing !== []) {
        throw new RuntimeException('Database contract is incomplete; missing tables: ' . implode(', ', $missing));
    }

    $requiredColumns = [
        'users' => ['uid', 'password_hash', 'lastname', 'avatar', 'role', 'is_active'],
        'user_to_dialogs' => ['role', 'last_read_message_id', 'last_delivered_message_id', 'is_deleted'],
        'messages' => ['from_user_id', 'message', 'message_type', 'reply_to_message_id', 'meta_data'],
        'note_attachments' => ['file_uid', 'file_path', 'mime_type', 'is_encrypted'],
        'system_settings' => ['setting_key', 'setting_value', 'setting_type', 'is_editable'],
        'user_storage_quotas' => ['user_id', 'quota_bytes'],
    ];
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            $stmt = $db->prepare(
                'SELECT 1 FROM information_schema.columns '
                . 'WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
            );
            $stmt->bind_param('ss', $table, $column);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows === 1;
            $stmt->close();
            if (!$exists) {
                throw new RuntimeException("Database contract is incomplete; missing column {$table}.{$column}");
            }
        }
    }

    $typeResult = $db->query(
        "SELECT COLUMN_TYPE AS contract_column_type FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'dialogs' AND column_name = 'type' LIMIT 1"
    );
    $typeRow = $typeResult->fetch_assoc();
    $type = (string) ($typeRow['contract_column_type'] ?? '');
    if (!str_contains($type, "'saved'")) {
        throw new RuntimeException('Database contract is incomplete; dialogs.type does not support saved');
    }
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
        if ($statusOnly && $pending === []) {
            verifyCurrentContract($db, $currentTables);
            echo "Schema contract: OK\n";
        }
        $db->close();
        exit(0);
    }

    ensureMigrationTable($db);
    foreach ($pending as $filename) {
        [, $sql, $checksum] = readMigration($root, $filename);
        echo "Applying {$filename} ... ";
        executeMigration($db, $sql);
        recordMigration($db, $filename, $checksum);
        echo "OK\n";
    }

    verifyCurrentContract($db, $currentTables);
    echo $pending === []
        ? "Database is up to date. Schema contract: OK\n"
        : 'Applied ' . count($pending) . " migration(s). Schema contract: OK\n";
    $db->close();
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
