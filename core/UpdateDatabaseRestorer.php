<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/UpdatePath.php';

use mysqli;
use mysqli_result;
use RuntimeException;

/**
 * Database rollback boundary for the transactional updater.
 *
 * It validates the external dump, removes objects introduced by failed
 * migrations, restores the snapshot and verifies the restored table/trigger
 * contract.
 */
final class UpdateDatabaseRestorer
{
    /** @var array<string,array<string,true>> */
    private array $jsonColumnsCache = [];

    /**
     * @param array<string,mixed> $databaseMetadata
     * @return array<string,mixed>
     */
    public function restore(mysqli $db, string $backupDir, array $databaseMetadata): array
    {
        $backupDirReal = realpath($backupDir);
        if (!is_string($backupDirReal) || !is_dir($backupDirReal) || is_link($backupDir)) {
            throw new RuntimeException('Rollback backup directory cannot be resolved safely');
        }
        $backupDirReal = UpdatePath::normalize($backupDirReal);

        $relative = (string) ($databaseMetadata['path'] ?? '');
        if (!UpdatePath::safeRelative($relative)) {
            throw new RuntimeException('Rollback database dump path is invalid');
        }

        $dumpPath = $backupDirReal . '/' . $relative;
        if (!is_file($dumpPath) || is_link($dumpPath)) {
            throw new RuntimeException('Rollback database dump is missing or unsafe');
        }

        $size = filesize($dumpPath);
        $hash = hash_file('sha256', $dumpPath);
        if (
            !is_int($size)
            || $size !== (int) ($databaseMetadata['bytes'] ?? -1)
            || !is_string($hash)
            || !hash_equals((string) ($databaseMetadata['sha256'] ?? ''), $hash)
        ) {
            throw new RuntimeException('Rollback database dump failed final SHA-256/size verification');
        }

        $sql = file_get_contents($dumpPath);
        if (!is_string($sql) || $sql === '') {
            throw new RuntimeException('Rollback database dump cannot be read');
        }

        $this->dropCurrentDatabaseObjects($db);
        foreach ($this->parseSqlStatements($sql) as $index => $statement) {
            try {
                $statement = $this->normalizeLegacyJsonInsert($db, $statement);
                $result = $db->query($statement);
                if ($result instanceof mysqli_result) {
                    $result->free();
                }
                $this->drainResults($db);
            } catch (\Throwable $e) {
                $summary = preg_replace('/\\s+/u', ' ', trim($statement));
                $summary = is_string($summary) ? mb_substr($summary, 0, 180) : 'неизвестный SQL';
                throw new RuntimeException(
                    'Восстановление БД остановилось на SQL #' . ($index + 1) . ': ' . $summary . '; ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        $verified = $this->verifyRestoredDatabase($db, $databaseMetadata);

        return [
            'dump_sha256' => $hash,
            'dump_bytes' => $size,
        ] + $verified + ['restored_at' => time()];
    }

    private function dropCurrentDatabaseObjects(mysqli $db): void
    {
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        try {
            $viewNames = [];
            $views = $db->query("SELECT TABLE_NAME FROM information_schema.views WHERE table_schema=DATABASE() ORDER BY TABLE_NAME");
            while ($row = $views->fetch_assoc()) {
                $viewNames[] = (string) $row['TABLE_NAME'];
            }
            $views->free();
            foreach ($viewNames as $name) {
                $db->query('DROP VIEW IF EXISTS ' . $this->quoteIdentifier($name));
            }

            $eventNames = [];
            $events = $db->query("SELECT EVENT_NAME FROM information_schema.events WHERE event_schema=DATABASE() ORDER BY EVENT_NAME");
            while ($row = $events->fetch_assoc()) {
                $eventNames[] = (string) $row['EVENT_NAME'];
            }
            $events->free();
            foreach ($eventNames as $name) {
                $db->query('DROP EVENT IF EXISTS ' . $this->quoteIdentifier($name));
            }

            $routineNames = [];
            $routines = $db->query(
                "SELECT ROUTINE_NAME,ROUTINE_TYPE FROM information_schema.routines WHERE routine_schema=DATABASE() ORDER BY ROUTINE_NAME"
            );
            while ($row = $routines->fetch_assoc()) {
                $routineNames[] = [(string) $row['ROUTINE_NAME'], strtoupper((string) $row['ROUTINE_TYPE'])];
            }
            $routines->free();
            foreach ($routineNames as [$name, $type]) {
                if (!in_array($type, ['PROCEDURE', 'FUNCTION'], true)) {
                    throw new RuntimeException('Unsupported database routine type during rollback');
                }
                $db->query('DROP ' . $type . ' IF EXISTS ' . $this->quoteIdentifier($name));
            }

            $tables = $db->query(
                "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"
            );
            $names = [];
            while ($row = $tables->fetch_assoc()) {
                $names[] = $this->quoteIdentifier((string) $row['TABLE_NAME']);
            }
            $tables->free();
            if ($names !== []) {
                $db->query('DROP TABLE IF EXISTS ' . implode(',', $names));
            }
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /** @return list<string> */
    private function parseSqlStatements(string $sql): array
    {
        $delimiter = ';';
        $buffer = '';
        $statements = [];
        $lines = preg_split('/\R/u', $sql);
        if ($lines === false) {
            throw new RuntimeException('Rollback SQL is not valid UTF-8 text');
        }

        foreach ($lines as $line) {
            if (preg_match('/^\s*--/', $line) === 1) {
                continue;
            }
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
                if (trim($buffer) !== '') {
                    throw new RuntimeException('Rollback SQL changed DELIMITER before statement ended');
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
            throw new RuntimeException('Rollback SQL contains unterminated statement');
        }

        return $statements;
    }

    /**
     * Старые rollback-дампы mysql-sql-v1 записывали все строки как X'HEX'.
     * Для JSON MySQL трактует такой литерал как binary и отказывает в загрузке.
     * Сам проверенный backup не меняем: преобразуем только JSON-значения
     * непосредственно перед выполнением INSERT.
     */
    private function normalizeLegacyJsonInsert(mysqli $db, string $statement): string
    {
        if (
            preg_match(
                '/^INSERT INTO `((?:``|[^`])+)` \\((.+)\\) VALUES \\((.*)\\)$/s',
                $statement,
                $match
            ) !== 1
        ) {
            return $statement;
        }

        $table = str_replace('``', '`', $match[1]);
        $columnCount = preg_match_all('/`((?:``|[^`])+)`/', $match[2], $columnMatches);
        if (!is_int($columnCount) || $columnCount < 1) {
            throw new RuntimeException("Не удалось разобрать колонки rollback INSERT для таблицы {$table}");
        }

        $columns = array_map(
            static fn (string $name): string => str_replace('``', '`', $name),
            $columnMatches[1]
        );
        $values = $this->splitSerializedValues($match[3]);
        if (count($columns) !== count($values)) {
            throw new RuntimeException("Количество колонок и значений rollback INSERT не совпадает для таблицы {$table}");
        }

        $jsonColumns = $this->jsonColumns($db, $table);
        if ($jsonColumns === []) {
            return $statement;
        }

        foreach ($columns as $index => $column) {
            if (!isset($jsonColumns[$column])) {
                continue;
            }

            $value = $values[$index];
            if ($value === 'NULL' || preg_match("/^CONVERT\\(X'[0-9A-F]*' USING utf8mb4\\)$/Di", $value) === 1) {
                continue;
            }

            if (preg_match("/^X'[0-9A-F]*'$/D", $value) !== 1) {
                throw new RuntimeException(
                    "Legacy rollback содержит неподдерживаемое JSON-значение {$table}.{$column}"
                );
            }

            $values[$index] = 'CONVERT(' . $value . ' USING utf8mb4)';
        }

        return 'INSERT INTO '
            . $this->quoteIdentifier($table)
            . ' (' . $match[2] . ') VALUES ('
            . implode(',', $values)
            . ')';
    }

    /** @return array<string,true> */
    private function jsonColumns(mysqli $db, string $table): array
    {
        if (array_key_exists($table, $this->jsonColumnsCache)) {
            return $this->jsonColumnsCache[$table];
        }

        $escapedTable = $db->real_escape_string($table);
        $result = $db->query(
            "SELECT COLUMN_NAME FROM information_schema.columns "
            . "WHERE table_schema=DATABASE() "
            . "AND table_name='{$escapedTable}' "
            . "AND data_type='json' ORDER BY ORDINAL_POSITION"
        );

        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $name = (string) ($row['COLUMN_NAME'] ?? '');
            if ($name !== '') {
                $columns[$name] = true;
            }
        }
        $result->free();

        $this->jsonColumnsCache[$table] = $columns;
        return $columns;
    }

    /** @return list<string> */
    private function splitSerializedValues(string $input): array
    {
        $values = [];
        $buffer = '';
        $depth = 0;
        $quoted = false;
        $length = strlen($input);

        for ($index = 0; $index < $length; $index++) {
            $char = $input[$index];

            if ($char === "'") {
                $quoted = !$quoted;
                $buffer .= $char;
                continue;
            }

            if (!$quoted && $char === '(') {
                $depth++;
                $buffer .= $char;
                continue;
            }

            if (!$quoted && $char === ')') {
                $depth--;
                if ($depth < 0) {
                    throw new RuntimeException('Rollback INSERT содержит несбалансированные скобки');
                }
                $buffer .= $char;
                continue;
            }

            if (!$quoted && $depth === 0 && $char === ',') {
                $values[] = trim($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if ($quoted || $depth !== 0) {
            throw new RuntimeException('Rollback INSERT содержит незавершённое значение');
        }

        $values[] = trim($buffer);
        foreach ($values as $value) {
            if ($value === '') {
                throw new RuntimeException('Rollback INSERT содержит пустое значение');
            }
        }

        return $values;
    }

    /** @return array{tables:int,triggers:int} */
    private function verifyRestoredDatabase(mysqli $db, array $metadata): array
    {
        $expected = [];
        foreach (($metadata['table_metadata'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['name'], $row['rows'])) {
                throw new RuntimeException('Rollback database metadata is incomplete');
            }
            $expected[(string) $row['name']] = (int) $row['rows'];
        }
        if ($expected === []) {
            throw new RuntimeException('Rollback database metadata contains no tables');
        }

        $result = $db->query(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"
        );
        $actualNames = [];
        while ($row = $result->fetch_assoc()) {
            $actualNames[] = (string) $row['TABLE_NAME'];
        }
        $result->free();

        $expectedNames = array_keys($expected);
        sort($expectedNames, SORT_STRING);
        if ($actualNames !== $expectedNames) {
            throw new RuntimeException('Restored database table set does not match rollback snapshot');
        }

        foreach ($expected as $table => $rows) {
            $countResult = $db->query('SELECT COUNT(*) AS c FROM ' . $this->quoteIdentifier($table));
            $actual = (int) ($countResult->fetch_assoc()['c'] ?? -1);
            $countResult->free();
            if ($actual !== $rows) {
                throw new RuntimeException("Restored database row count mismatch for {$table}");
            }
        }

        $triggerResult = $db->query(
            "SELECT COUNT(*) AS c FROM information_schema.triggers WHERE trigger_schema=DATABASE()"
        );
        $triggers = (int) ($triggerResult->fetch_assoc()['c'] ?? -1);
        $triggerResult->free();
        if ($triggers !== (int) ($metadata['triggers'] ?? -2)) {
            throw new RuntimeException('Restored database trigger count does not match rollback snapshot');
        }

        return ['tables' => count($expected), 'triggers' => $triggers];
    }

    private function drainResults(mysqli $db): void
    {
        while ($db->more_results()) {
            $db->next_result();
            if ($result = $db->store_result()) {
                $result->free();
            }
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
