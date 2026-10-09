<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/UpdatePath.php';
require_once __DIR__ . '/HostingCompatibility.php';
require_once __DIR__ . '/UpdateDatabaseRestorer.php';
require_once __DIR__ . '/UpdateStepBudget.php';

use mysqli;
use mysqli_result;
use RuntimeException;
use Throwable;

/**
 * Создаёт rollback-артефакты до первого изменения рабочей версии.
 *
 * Резервная точка собирается во внешнем временном каталоге, проверяется по
 * хэшам и публикуется атомарно только после готовности кода и дампа MySQL.
 */
final class UpdateBackupManager
{
    private const SCHEMA = 1;
    private const LOCK_FILENAME = '.update-backup.lock';
    private const MIN_BACKUP_HEADROOM_BYTES = 32 * 1024 * 1024;
    private const MIN_BACKUP_CAPACITY_BYTES = 64 * 1024 * 1024;
    private const DATABASE_DUMP_EXPANSION_FACTOR = 3;

    /** @var list<string> */
    private const DEFAULT_EXCLUDED_ROOTS = [
        '.git',
        'vendor',
        'cache',
        'compile',
        'uploads',
        'notes-private-storage',
        '.logs',
        'update-continuations',
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
    public function create(string $transactionId, mysqli $db, ?UpdateStepBudget $budget = null): array
    {
        $this->validateTransactionId($transactionId);

        return $this->withLock(function () use ($transactionId, $db, $budget): array {
            $finalDir = $this->backupRoot . DIRECTORY_SEPARATOR . $transactionId;
            if (is_dir($finalDir)) {
                return $this->verify($finalDir, $transactionId);
            }
            if (file_exists($finalDir) || is_link($finalDir)) {
                throw new RuntimeException('Updater backup target already exists but is not a safe directory');
            }

            $this->assertCapacity($db);

            $tempDir = $this->backupRoot . DIRECTORY_SEPARATOR . ($budget === null
                ? '.tmp-' . $transactionId . '-' . bin2hex(random_bytes(6))
                : '.pending-' . $transactionId);
            if (is_link($tempDir) || (file_exists($tempDir) && !is_dir($tempDir))) {
                throw new RuntimeException('Небезопасная незавершённая резервная точка');
            }
            $oldUmask = umask(0077);
            $made = is_dir($tempDir) || @mkdir($tempDir, 0700, false);
            umask($oldUmask);
            if (!$made || !is_dir($tempDir)) {
                throw new RuntimeException('Cannot create temporary updater backup directory');
            }

            try {
                // Смерть PHP после полной записи manifest, но до rename: повторно
                // проверяем уже согласованный снимок, не создавая новую точку БД.
                if ($budget !== null && is_file($tempDir . '/backup.json')) {
                    $this->verifyDirectory($tempDir, $transactionId);
                    if (!rename($tempDir, $finalDir)) throw new RuntimeException('Не удалось опубликовать резервную точку');
                    return $this->verify($finalDir, $transactionId);
                }
                $code = $this->snapshotCode($tempDir, $budget);
                // Снимок БД пока выполняется одной транзакцией. Незавершённый
                // дамп после смерти PHP никогда не продолжается из другой snapshot.
                if ($budget !== null && file_exists($tempDir . '/database.sql')) {
                    if (is_link($tempDir . '/database.sql') || !unlink($tempDir . '/database.sql')) {
                        throw new RuntimeException('Не удалось удалить незавершённый дамп БД');
                    }
                }
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
            } catch (UpdateStepPending $pause) {
                throw $pause;
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

    private function assertCapacity(mysqli $db): void
    {
        $freeBytes = HostingCompatibility::freeDiskBytes($this->backupRoot);
        if ($freeBytes === null) {
            return;
        }

        $codeBytes = $this->estimateCodeBytes($this->appRoot);
        $databaseBytes = $this->estimateDatabaseBytes($db);
        $requiredBytes = max(
            self::MIN_BACKUP_CAPACITY_BYTES,
            $codeBytes
                + ($databaseBytes * self::DATABASE_DUMP_EXPANSION_FACTOR)
                + self::MIN_BACKUP_HEADROOM_BYTES
        );

        if ($freeBytes < $requiredBytes) {
            throw new RuntimeException(
                sprintf(
                    'Недостаточно свободного места для rollback backup: доступно %.1f МБ, требуется ориентировочно %.1f МБ',
                    $freeBytes / 1048576,
                    $requiredBytes / 1048576
                )
            );
        }
    }

    private function estimateCodeBytes(string $directory, string $relative = ''): int
    {
        $items = scandir($directory);
        if (!is_array($items)) {
            throw new RuntimeException('Не удалось оценить размер кода перед резервным копированием');
        }

        $bytes = 0;
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $relativePath = $relative === '' ? $name : $relative . '/' . $name;
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if ($this->shouldExclude($path, $relativePath)) {
                continue;
            }
            if (is_link($path)) {
                throw new RuntimeException('Rollback backup не допускает symlink: ' . $relativePath);
            }
            if (is_dir($path)) {
                $bytes += $this->estimateCodeBytes($path, $relativePath);
                continue;
            }

            $size = is_file($path) ? filesize($path) : false;
            if (!is_int($size) || $size < 0) {
                throw new RuntimeException('Не удалось оценить размер файла перед backup: ' . $relativePath);
            }
            $bytes += $size;
        }

        return $bytes;
    }

    private function estimateDatabaseBytes(mysqli $db): int
    {
        $result = $db->query(
            'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS estimated_bytes '
            . 'FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        );
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }

        $value = $row['estimated_bytes'] ?? 0;
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /** @return array<string,mixed> */
    private function snapshotCode(string $tempDir, ?UpdateStepBudget $budget = null): array
    {
        $destination = $tempDir . DIRECTORY_SEPARATOR . 'code';
        if (is_link($destination) || (!is_dir($destination) && !mkdir($destination, 0700, false))) {
            throw new RuntimeException('Cannot create updater code snapshot directory');
        }

        $entries = [];
        $totalBytes = 0;
        $this->copyCodeDirectory($this->appRoot, $destination, '', $entries, $totalBytes, $budget);
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
        if ($budget !== null && file_exists($manifestPath)) {
            if (is_link($manifestPath) || !unlink($manifestPath)) throw new RuntimeException('Небезопасный code manifest');
        }
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
    private function copyCodeDirectory(string $sourceDir, string $destinationDir, string $relative, array &$entries, int &$totalBytes, ?UpdateStepBudget $budget = null): void
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
                if (is_link($dest) || (!is_dir($dest) && !mkdir($dest, 0700, false))) {
                    throw new RuntimeException("Cannot create code backup directory: {$relativePath}");
                }
                $this->copyCodeDirectory($source, $dest, $relativePath, $entries, $totalBytes, $budget);
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
            $alreadyCopied = false;
            if ($budget !== null && file_exists($dest)) {
                if (is_link($dest) || !is_file($dest)) throw new RuntimeException('Небезопасный файл резервной точки');
                $alreadyCopied = filesize($dest) === $size && hash_equals($sourceHash, (string) hash_file('sha256', $dest));
                if (!$alreadyCopied && !unlink($dest)) throw new RuntimeException('Не удалось повторить неполную копию файла');
            }
            if (!$alreadyCopied) $this->copyVerified($source, $dest, $size, $sourceHash);
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
            if ($budget !== null && !$alreadyCopied) $budget->checkpoint('backup');
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
                $columnMetadata = $this->tableColumns($db, $table);
                if ($columnMetadata === []) {
                    throw new RuntimeException("Не удалось определить колонки таблицы {$table} для резервной копии");
                }
                $columns = array_map(
                    fn (array $column): string => $this->quoteIdentifier($column['name']),
                    $columnMetadata
                );
                $result = $db->query(
                    'SELECT ' . implode(',', $columns) . ' FROM ' . $quotedTable,
                    MYSQLI_USE_RESULT
                );
                if (!$result instanceof mysqli_result) {
                    throw new RuntimeException("Не удалось прочитать таблицу {$table} для резервной копии");
                }
                $fields = $result->fetch_fields();
                if (count($fields) !== count($columnMetadata)) {
                    throw new RuntimeException("Метаданные колонок таблицы {$table} изменились во время резервного копирования");
                }

                $rows = 0;
                while ($row = $result->fetch_row()) {
                    $values = [];
                    foreach ($row as $index => $value) {
                        $values[] = $this->sqlLiteral(
                            $value,
                            (int) $fields[$index]->type,
                            $columnMetadata[$index]['data_type']
                        );
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
                $statement = $this->portableTriggerDefinition($statement);
                $this->writeAll($handle, "DELIMITER $\nDROP TRIGGER IF EXISTS " . $this->quoteIdentifier($name) . "$\n");
                $this->writeAll($handle, $statement . "$\nDELIMITER ;\n\n");
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
        (new UpdateDatabaseRestorer())->validateDump($databasePath);

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
            if (function_exists('fsync') && !fsync($output)) {
                throw new RuntimeException('Не удалось синхронизировать файл резервной точки');
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

    /**
     * @return list<array{name:string,data_type:string}>
     */
    private function tableColumns(mysqli $db, string $table): array
    {
        $escapedTable = $db->real_escape_string($table);
        $result = $db->query(
            "SELECT COLUMN_NAME,DATA_TYPE,EXTRA FROM information_schema.columns "
            . "WHERE table_schema=DATABASE() AND table_name='{$escapedTable}' ORDER BY ORDINAL_POSITION"
        );

        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $extra = strtoupper((string) ($row['EXTRA'] ?? ''));
            if (str_contains($extra, 'GENERATED')) {
                continue;
            }
            $name = (string) ($row['COLUMN_NAME'] ?? '');
            $dataType = strtolower((string) ($row['DATA_TYPE'] ?? ''));
            if ($name === '' || $dataType === '') {
                $result->free();
                throw new RuntimeException("Некорректные метаданные колонки таблицы {$table}");
            }
            $columns[] = ['name' => $name, 'data_type' => $dataType];
        }
        $result->free();

        return $columns;
    }

    private function sqlLiteral(mixed $value, int $type, string $dataType = ''): string
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

        $dataType = strtolower(trim($dataType));
        $hex = strtoupper(bin2hex($string));
        $binaryTypes = [
            'bit',
            'binary',
            'varbinary',
            'tinyblob',
            'blob',
            'mediumblob',
            'longblob',
            'geometry',
            'point',
            'linestring',
            'polygon',
            'multipoint',
            'multilinestring',
            'multipolygon',
            'geometrycollection',
        ];
        if (in_array($dataType, $binaryTypes, true)) {
            return "X'{$hex}'";
        }

        if (preg_match('//u', $string) !== 1) {
            throw new RuntimeException(
                "Текстовая колонка типа {$dataType} содержит данные, которые не являются корректным UTF-8"
            );
        }

        if ($dataType === 'json' || $type === MYSQLI_TYPE_JSON) {
            try {
                json_decode($string, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new RuntimeException('JSON-колонка содержит некорректное значение', 0, $e);
            }
        }

        // Явное преобразование из hex в utf8mb4 сохраняет точные байты строки,
        // но не отдаёт MySQL значение с CHARACTER SET binary. Это одинаково
        // безопасно для JSON и обычных текстовых колонок.
        return "CONVERT(X'{$hex}' USING utf8mb4)";
    }

    private function portableTriggerDefinition(string $statement): string
    {
        $statement = trim($statement);
        if (preg_match('/^CREATE\\s+TRIGGER\\b/i', $statement) === 1) {
            return $statement;
        }

        $portable = preg_replace(
            '/^CREATE\\s+DEFINER\\s*=\\s*(?:`(?:``|[^`])*`|[^@\\s]+)\\s*@\\s*(?:`(?:``|[^`])*`|[^\\s]+)\\s+TRIGGER\\b/i',
            'CREATE TRIGGER',
            $statement,
            1
        );
        if (!is_string($portable) || preg_match('/^CREATE\\s+TRIGGER\\b/i', $portable) !== 1) {
            throw new RuntimeException('Не удалось подготовить переносимое определение триггера для rollback');
        }

        return $portable;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /** @param array<string,mixed> $value */
    private function writeJsonExclusive(string $path, array $value): void
    {
        $bytes = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Updater backup metadata already exists');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $oldUmask = umask(0077);
        $handle = @fopen($temporary, 'xb');
        umask($oldUmask);
        if ($handle === false) {
            throw new RuntimeException('Cannot create updater backup metadata file');
        }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new RuntimeException('Cannot write updater backup metadata file');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('Не удалось синхронизировать manifest резервной точки');
            }
        } finally {
            fclose($handle);
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) throw new RuntimeException('Не удалось опубликовать manifest резервной точки');
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
