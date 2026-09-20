<?php

declare(strict_types=1);

namespace Core;

use mysqli;
use mysqli_result;
use RuntimeException;
use Throwable;

/**
 * Creates rollback artifacts before any updater live mutation begins.
 *
 * Backups are assembled under a temporary external directory, hash-verified and
 * atomically renamed into their final transaction directory only after both the
 * code snapshot and MySQL dump are complete.
 */
final class UpdateBackupManager
{
    private const SCHEMA = 1;
    private const LOCK_FILENAME = '.update-backup.lock';

    /** @var list<string> */
    private const DEFAULT_EXCLUDED_ROOTS = [
        '.git',
        'vendor',
        'cache',
        'compile',
        'uploads',
        'notes-private-storage',
        '.logs',
    ];

    private string $appRoot;
    private string $backupRoot;

    /** @var list<string> */
    private array $excludedAbsolutePaths = [];

    /**
     * @param list<string> $excludedAbsolutePaths mutable paths that must not be restored as application code
     */
    public function __construct(string $backupRoot, ?string $appRoot = null, array $excludedAbsolutePaths = [])
    {
        $resolvedApp = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($resolvedApp) || !is_dir($resolvedApp)) {
            throw new RuntimeException('Application root cannot be resolved for updater backup');
        }
        $this->appRoot = $this->normalize($resolvedApp);
        $this->backupRoot = $this->prepareExternalRoot($backupRoot);

        foreach ($excludedAbsolutePaths as $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            $resolved = realpath($path);
            if (is_string($resolved)) {
                $this->excludedAbsolutePaths[] = $this->normalize($resolved);
            }
        }
    }

    /**
     * @return array{backup_dir:string,manifest_path:string,manifest_sha256:string,code:array<string,mixed>,database:array<string,mixed>}
     */
    public function create(string $transactionId, mysqli $db): array
    {
        $this->validateTransactionId($transactionId);

        return $this->withLock(function () use ($transactionId, $db): array {
            $finalDir = $this->backupRoot . DIRECTORY_SEPARATOR . $transactionId;
            if (is_dir($finalDir)) {
                return $this->verify($finalDir, $transactionId);
            }
            if (file_exists($finalDir) || is_link($finalDir)) {
                throw new RuntimeException('Updater backup target already exists but is not a safe directory');
            }

            $tempDir = $this->backupRoot . DIRECTORY_SEPARATOR . '.tmp-' . $transactionId . '-' . bin2hex(random_bytes(6));
            $oldUmask = umask(0077);
            $made = @mkdir($tempDir, 0700, false);
            umask($oldUmask);
            if (!$made || !is_dir($tempDir)) {
                throw new RuntimeException('Cannot create temporary updater backup directory');
            }

            try {
                $code = $this->snapshotCode($tempDir);
                $database = $this->dumpDatabase($db, $tempDir . DIRECTORY_SEPARATOR . 'database.sql');

                $manifest = [
                    'schema' => self::SCHEMA,
                    'transaction_id' => $transactionId,
                    'created_at' => time(),
                    'application_root' => $this->appRoot,
                    'code' => $code,
                    'database' => $database,
                ];
                $manifestPath = $tempDir . DIRECTORY_SEPARATOR . 'backup.json';
                $this->writeJsonExclusive($manifestPath, $manifest);

                $this->verifyDirectory($tempDir, $transactionId);
                if (!@rename($tempDir, $finalDir)) {
                    throw new RuntimeException('Cannot atomically finalize updater backup directory');
                }
                @chmod($finalDir, 0700);
                return $this->verify($finalDir, $transactionId);
            } catch (Throwable $e) {
                if (is_dir($tempDir)) {
                    $this->removeTree($tempDir);
                }
                throw $e;
            }
        });
    }

    /**
     * @return array{backup_dir:string,manifest_path:string,manifest_sha256:string,code:array<string,mixed>,database:array<string,mixed>}
     */
    public function verify(string $backupDir, ?string $expectedTransactionId = null): array
    {
        $real = realpath($backupDir);
        if (!is_string($real) || !is_dir($real) || is_link($backupDir)) {
            throw new RuntimeException('Updater backup directory cannot be resolved safely');
        }
        $real = $this->normalize($real);
        if (!$this->pathInside($real, $this->backupRoot)) {
            throw new RuntimeException('Updater backup directory is outside configured backup root');
        }

        $manifest = $this->verifyDirectory($real, $expectedTransactionId);
        $manifestPath = $real . DIRECTORY_SEPARATOR . 'backup.json';
        $manifestHash = hash_file('sha256', $manifestPath);
        if (!is_string($manifestHash)) {
            throw new RuntimeException('Cannot hash updater backup manifest');
        }

        return [
            'backup_dir' => $real,
            'manifest_path' => $manifestPath,
            'manifest_sha256' => $manifestHash,
            'code' => $manifest['code'],
            'database' => $manifest['database'],
        ];
    }

    /** @return array<string,mixed> */
    private function snapshotCode(string $tempDir): array
    {
        $destination = $tempDir . DIRECTORY_SEPARATOR . 'code';
        if (!mkdir($destination, 0700, false) && !is_dir($destination)) {
            throw new RuntimeException('Cannot create updater code snapshot directory');
        }

        $entries = [];
        $totalBytes = 0;
        $this->copyCodeDirectory($this->appRoot, $destination, '', $entries, $totalBytes);
        usort($entries, static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));

        if ($entries === []) {
            throw new RuntimeException('Updater code snapshot is unexpectedly empty');
        }

        $manifest = [
            'schema' => self::SCHEMA,
            'files' => count($entries),
            'bytes' => $totalBytes,
            'excluded_roots' => self::DEFAULT_EXCLUDED_ROOTS,
            'entries' => $entries,
        ];
        $manifestPath = $tempDir . DIRECTORY_SEPARATOR . 'code-manifest.json';
        $this->writeJsonExclusive($manifestPath, $manifest);
        $manifestHash = hash_file('sha256', $manifestPath);
        if (!is_string($manifestHash)) {
            throw new RuntimeException('Cannot hash updater code manifest');
        }

        $this->verifyCodeSnapshot($tempDir, $manifest, $manifestHash);
        return [
            'path' => 'code',
            'manifest' => 'code-manifest.json',
            'manifest_sha256' => $manifestHash,
            'files' => count($entries),
            'bytes' => $totalBytes,
        ];
    }

    /**
     * @param list<array{path:string,sha256:string,size:int,mode:int}> $entries
     */
    private function copyCodeDirectory(string $sourceDir, string $destinationDir, string $relative, array &$entries, int &$totalBytes): void
    {
        $items = scandir($sourceDir);
        if (!is_array($items)) {
            throw new RuntimeException('Cannot read application directory during updater backup');
        }

        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $relativePath = $relative === '' ? $name : $relative . '/' . $name;
            $source = $sourceDir . DIRECTORY_SEPARATOR . $name;

            if ($this->shouldExclude($source, $relativePath)) {
                continue;
            }
            if (is_link($source)) {
                throw new RuntimeException("Updater code backup refuses symlink: {$relativePath}");
            }
            if (is_dir($source)) {
                $dest = $destinationDir . DIRECTORY_SEPARATOR . $name;
                if (!mkdir($dest, 0700, false) && !is_dir($dest)) {
                    throw new RuntimeException("Cannot create code backup directory: {$relativePath}");
                }
                $this->copyCodeDirectory($source, $dest, $relativePath, $entries, $totalBytes);
                continue;
            }
            if (!is_file($source) || !is_readable($source)) {
                throw new RuntimeException("Updater code backup encountered unreadable path: {$relativePath}");
            }

            $size = filesize($source);
            if (!is_int($size) || $size < 0) {
                throw new RuntimeException("Cannot determine application file size: {$relativePath}");
            }
            $sourceHash = hash_file('sha256', $source);
            if (!is_string($sourceHash)) {
                throw new RuntimeException("Cannot hash application file: {$relativePath}");
            }

            $dest = $destinationDir . DIRECTORY_SEPARATOR . $name;
            $this->copyVerified($source, $dest, $size, $sourceHash);
            $permissions = fileperms($source);
            $mode = is_int($permissions) ? ($permissions & 0777) : 0644;
            @chmod($dest, $mode);

            $entries[] = [
                'path' => str_replace('\\', '/', $relativePath),
                'sha256' => $sourceHash,
                'size' => $size,
                'mode' => $mode,
            ];
            $totalBytes += $size;
        }
    }

    private function shouldExclude(string $absolutePath, string $relativePath): bool
    {
        $top = explode('/', str_replace('\\', '/', $relativePath), 2)[0];
        if (in_array($top, self::DEFAULT_EXCLUDED_ROOTS, true)) {
            return true;
        }
        if ($relativePath === '.env' || str_starts_with($relativePath, '.env.')) {
            return true;
        }
        if (str_starts_with($relativePath, 'tools/vendor-license/') || str_starts_with($relativePath, 'tools/vendor-update/')) {
            return true;
        }

        $normalized = $this->normalize($absolutePath);
        foreach ($this->excludedAbsolutePaths as $excluded) {
            if ($this->pathInside($normalized, $excluded)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function dumpDatabase(mysqli $db, string $path): array
    {
        $objects = $db->query(
            "SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.tables " .
            "WHERE table_schema=DATABASE() ORDER BY TABLE_NAME"
        );
        $tables = [];
        while ($row = $objects->fetch_assoc()) {
            $type = strtoupper((string) $row['TABLE_TYPE']);
            if ($type !== 'BASE TABLE') {
                throw new RuntimeException('Updater database backup does not permit views or unsupported table objects');
            }
            $engine = strtoupper((string) ($row['ENGINE'] ?? ''));
            if ($engine !== 'INNODB') {
                throw new RuntimeException('Updater database backup requires InnoDB tables for a consistent snapshot');
            }
            $tables[] = (string) $row['TABLE_NAME'];
        }
        if ($tables === []) {
            throw new RuntimeException('Updater database backup found no tables');
        }

        $routineCount = (int) ($db->query(
            "SELECT COUNT(*) AS c FROM information_schema.routines WHERE routine_schema=DATABASE()"
        )->fetch_assoc()['c'] ?? 0);
        $eventCount = (int) ($db->query(
            "SELECT COUNT(*) AS c FROM information_schema.events WHERE event_schema=DATABASE()"
        )->fetch_assoc()['c'] ?? 0);
        if ($routineCount !== 0 || $eventCount !== 0) {
            throw new RuntimeException('Updater database backup refuses unsupported routines/events');
        }

        $oldUmask = umask(0077);
        $handle = @fopen($path, 'xb');
        umask($oldUmask);
        if ($handle === false) {
            throw new RuntimeException('Cannot create updater MySQL backup file');
        }

        $tableMetadata = [];
        $triggerCount = 0;
        $db->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        try {
            $this->writeAll($handle, "-- Workspace Organizer updater rollback backup\n");
            $this->writeAll($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tables as $table) {
                $quotedTable = $this->quoteIdentifier($table);
                $createResult = $db->query('SHOW CREATE TABLE ' . $quotedTable);
                $createRow = $createResult->fetch_assoc();
                $createSql = is_array($createRow) ? (string) (array_values($createRow)[1] ?? '') : '';
                if ($createSql === '') {
                    throw new RuntimeException("Cannot read CREATE TABLE for {$table}");
                }

                $this->writeAll($handle, "DROP TABLE IF EXISTS {$quotedTable};\n{$createSql};\n");
                $result = $db->query('SELECT * FROM ' . $quotedTable, MYSQLI_USE_RESULT);
                if (!$result instanceof mysqli_result) {
                    throw new RuntimeException("Cannot stream table {$table}");
                }
                $fields = $result->fetch_fields();
                $columns = array_map(fn ($field): string => $this->quoteIdentifier((string) $field->name), $fields);
                $rows = 0;
                while ($row = $result->fetch_row()) {
                    $values = [];
                    foreach ($row as $index => $value) {
                        $values[] = $this->sqlLiteral($value, (int) $fields[$index]->type);
                    }
                    $this->writeAll(
                        $handle,
                        'INSERT INTO ' . $quotedTable . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n"
                    );
                    $rows++;
                }
                $result->free();
                $this->writeAll($handle, "\n");
                $tableMetadata[] = ['name' => $table, 'engine' => 'InnoDB', 'rows' => $rows];
            }

            $triggers = $db->query('SHOW TRIGGERS');
            while ($trigger = $triggers->fetch_assoc()) {
                $name = (string) ($trigger['Trigger'] ?? '');
                if ($name === '') {
                    continue;
                }
                $create = $db->query('SHOW CREATE TRIGGER ' . $this->quoteIdentifier($name))->fetch_assoc();
                $statement = '';
                if (is_array($create)) {
                    foreach ($create as $key => $value) {
                        if (stripos((string) $key, 'statement') !== false && is_string($value)) {
                            $statement = $value;
                            break;
                        }
                    }
                }
                if ($statement === '') {
                    throw new RuntimeException("Cannot read CREATE TRIGGER for {$name}");
                }
                $this->writeAll($handle, "DELIMITER $$\nDROP TRIGGER IF EXISTS " . $this->quoteIdentifier($name) . "$$\n");
                $this->writeAll($handle, $statement . "$$\nDELIMITER ;\n\n");
                $triggerCount++;
            }

            $this->writeAll($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            if (!fflush($handle)) {
                throw new RuntimeException('Cannot flush updater MySQL backup');
            }
            $db->commit();
        } catch (Throwable $e) {
            try {
                $db->rollback();
            } catch (Throwable) {
            }
            fclose($handle);
            @unlink($path);
            throw $e;
        }
        fclose($handle);
        @chmod($path, 0600);

        $size = filesize($path);
        $sha = hash_file('sha256', $path);
        if (!is_int($size) || $size <= 0 || !is_string($sha)) {
            throw new RuntimeException('Updater MySQL backup verification failed');
        }
        return [
            'path' => 'database.sql',
            'sha256' => $sha,
            'bytes' => $size,
            'tables' => count($tableMetadata),
            'triggers' => $triggerCount,
            'table_metadata' => $tableMetadata,
            'format' => 'mysql-sql-v1',
        ];
    }

    /** @return array<string,mixed> */
    private function verifyDirectory(string $dir, ?string $expectedTransactionId): array
    {
        $manifestPath = $dir . DIRECTORY_SEPARATOR . 'backup.json';
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            throw new RuntimeException('Updater backup manifest is missing or unsafe');
        }
        $manifestBytes = file_get_contents($manifestPath);
        if (!is_string($manifestBytes)) {
            throw new RuntimeException('Cannot read updater backup manifest');
        }
        $manifest = json_decode($manifestBytes, true);
        if (!is_array($manifest) || array_is_list($manifest) || ($manifest['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('Updater backup manifest failed schema validation');
        }
        $transactionId = (string) ($manifest['transaction_id'] ?? '');
        $this->validateTransactionId($transactionId);
        if ($expectedTransactionId !== null && !hash_equals($expectedTransactionId, $transactionId)) {
            throw new RuntimeException('Updater backup belongs to another transaction');
        }

        $code = $manifest['code'] ?? null;
        $database = $manifest['database'] ?? null;
        if (!is_array($code) || !is_array($database)) {
            throw new RuntimeException('Updater backup manifest is incomplete');
        }

        $codeManifestPath = $dir . DIRECTORY_SEPARATOR . (string) ($code['manifest'] ?? '');
        $codeHash = is_file($codeManifestPath) ? hash_file('sha256', $codeManifestPath) : false;
        if (!is_string($codeHash) || !hash_equals((string) ($code['manifest_sha256'] ?? ''), $codeHash)) {
            throw new RuntimeException('Updater code backup manifest SHA-256 mismatch');
        }
        $codeManifestBytes = file_get_contents($codeManifestPath);
        $codeManifest = is_string($codeManifestBytes) ? json_decode($codeManifestBytes, true) : null;
        if (!is_array($codeManifest)) {
            throw new RuntimeException('Updater code backup manifest is invalid');
        }
        $this->verifyCodeSnapshot($dir, $codeManifest, $codeHash);

        $databasePath = $dir . DIRECTORY_SEPARATOR . (string) ($database['path'] ?? '');
        if (!is_file($databasePath) || is_link($databasePath)) {
            throw new RuntimeException('Updater database backup file is missing or unsafe');
        }
        $dbSize = filesize($databasePath);
        $dbHash = hash_file('sha256', $databasePath);
        if (
            !is_int($dbSize)
            || $dbSize !== (int) ($database['bytes'] ?? -1)
            || !is_string($dbHash)
            || !hash_equals((string) ($database['sha256'] ?? ''), $dbHash)
        ) {
            throw new RuntimeException('Updater database backup SHA-256/size verification failed');
        }

        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    private function verifyCodeSnapshot(string $backupDir, array $manifest, string $expectedManifestHash): void
    {
        if (($manifest['schema'] ?? null) !== self::SCHEMA || !is_array($manifest['entries'] ?? null)) {
            throw new RuntimeException('Updater code backup manifest failed schema validation');
        }
        $codeRoot = $backupDir . DIRECTORY_SEPARATOR . 'code';
        $files = 0;
        $bytes = 0;
        foreach ($manifest['entries'] as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('Updater code backup entry is invalid');
            }
            $relative = (string) ($entry['path'] ?? '');
            if (!$this->safeRelativePath($relative)) {
                throw new RuntimeException('Updater code backup contains unsafe relative path');
            }
            $path = $codeRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($path) || is_link($path)) {
                throw new RuntimeException("Updater code backup file is missing: {$relative}");
            }
            $size = filesize($path);
            $hash = hash_file('sha256', $path);
            if (
                !is_int($size)
                || $size !== (int) ($entry['size'] ?? -1)
                || !is_string($hash)
                || !hash_equals((string) ($entry['sha256'] ?? ''), $hash)
            ) {
                throw new RuntimeException("Updater code backup verification failed: {$relative}");
            }
            $files++;
            $bytes += $size;
        }
        if ($files !== (int) ($manifest['files'] ?? -1) || $bytes !== (int) ($manifest['bytes'] ?? -1)) {
            throw new RuntimeException('Updater code backup aggregate verification failed');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $expectedManifestHash) !== 1) {
            throw new RuntimeException('Updater code backup manifest hash is invalid');
        }
    }

    private function copyVerified(string $source, string $destination, int $size, string $sha256): void
    {
        $input = @fopen($source, 'rb');
        $oldUmask = umask(0077);
        $output = @fopen($destination, 'xb');
        umask($oldUmask);
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('Cannot copy updater code backup file');
        }
        try {
            $copied = stream_copy_to_stream($input, $output);
            if ($copied !== $size || !fflush($output)) {
                throw new RuntimeException('Updater code backup copy is incomplete');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        $copiedHash = hash_file('sha256', $destination);
        if (!is_string($copiedHash) || !hash_equals($sha256, $copiedHash)) {
            @unlink($destination);
            throw new RuntimeException('Updater code backup failed post-copy SHA-256 verification');
        }
    }

    /** @param resource $handle */
    private function writeAll($handle, string $bytes): void
    {
        $length = strlen($bytes);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Cannot write updater database backup');
            }
            $offset += $written;
        }
    }

    private function sqlLiteral(mixed $value, int $type): string
    {
        if ($value === null) {
            return 'NULL';
        }
        $string = (string) $value;
        $numericTypes = [
            MYSQLI_TYPE_TINY,
            MYSQLI_TYPE_SHORT,
            MYSQLI_TYPE_LONG,
            MYSQLI_TYPE_FLOAT,
            MYSQLI_TYPE_DOUBLE,
            MYSQLI_TYPE_LONGLONG,
            MYSQLI_TYPE_INT24,
            MYSQLI_TYPE_YEAR,
            MYSQLI_TYPE_DECIMAL,
            MYSQLI_TYPE_NEWDECIMAL,
        ];
        if (in_array($type, $numericTypes, true) && is_numeric($string)) {
            return $string;
        }
        return "X'" . strtoupper(bin2hex($string)) . "'";
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /** @param array<string,mixed> $value */
    private function writeJsonExclusive(string $path, array $value): void
    {
        $bytes = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        $oldUmask = umask(0077);
        $handle = @fopen($path, 'xb');
        umask($oldUmask);
        if ($handle === false) {
            throw new RuntimeException('Cannot create updater backup metadata file');
        }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new RuntimeException('Cannot write updater backup metadata file');
            }
        } finally {
            fclose($handle);
        }
        @chmod($path, 0600);
    }

    /** @return mixed */
    private function withLock(callable $callback): mixed
    {
        $lockPath = $this->backupRoot . DIRECTORY_SEPARATOR . self::LOCK_FILENAME;
        if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
            throw new RuntimeException('Updater backup lock path is unsafe');
        }
        $oldUmask = umask(0077);
        $lock = @fopen($lockPath, 'c');
        umask($oldUmask);
        if ($lock === false) {
            throw new RuntimeException('Cannot open updater backup lock');
        }
        @chmod($lockPath, 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another updater backup operation is already running');
        }
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function prepareExternalRoot(string $path): string
    {
        $path = trim($path);
        if (!$this->isAbsolute($path) || is_link($path)) {
            throw new RuntimeException('Updater backup root must be an absolute non-symlink path');
        }
        if (!is_dir($path)) {
            $oldUmask = umask(0077);
            $made = @mkdir($path, 0700, true);
            umask($oldUmask);
            if (!$made && !is_dir($path)) {
                throw new RuntimeException('Cannot create updater backup root');
            }
        }
        $resolved = realpath($path);
        if (!is_string($resolved) || !is_dir($resolved) || !is_writable($resolved)) {
            throw new RuntimeException('Updater backup root cannot be resolved or is not writable');
        }
        $resolved = $this->normalize($resolved);
        if ($this->pathInside($resolved, $this->appRoot)) {
            throw new RuntimeException('Updater backup root must be outside the live application tree');
        }
        @chmod($resolved, 0700);
        return $resolved;
    }

    private function shouldExcludeByRoot(string $relativePath): bool
    {
        $top = explode('/', str_replace('\\', '/', $relativePath), 2)[0];
        return in_array($top, self::DEFAULT_EXCLUDED_ROOTS, true);
    }

    private function safeRelativePath(string $path): bool
    {
        return UpdatePath::safeRelative($path);
    }

    private function validateTransactionId(string $transactionId): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', trim($transactionId)) !== 1) {
            throw new RuntimeException('Invalid updater transaction id');
        }
    }

    private function isAbsolute(string $path): bool
    {
        return UpdatePath::isAbsolute($path);
    }

    private function normalize(string $path): string
    {
        return UpdatePath::normalize($path);
    }

    private function pathInside(string $path, string $parent): bool
    {
        return UpdatePath::inside($path, $parent);
    }

    private function removeTree(string $dir): void
    {
        UpdatePath::removeTree($dir);
    }

}
