<?php

declare(strict_types=1);

namespace Core;

use mysqli;
use mysqli_result;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/UpdateStepBudget.php';
require_once __DIR__ . '/UpdateStepCheckpoint.php';

/**
 * Пошаговое восстановление SQL. INSERT и курсор фиксируются одной транзакцией
 * InnoDB: смерть PHP после COMMIT не приводит к повторной вставке строки.
 * DDL повторяется только до начала данных своей таблицы.
 */
final class UpdateDatabaseRestoreSteps
{
    public function run(
        mysqli $db, string $dump, string $identity, array $metadata,
        UpdateStepBudget $budget, \Closure $statements,
        \Closure $normalize, \Closure $verify
    ): array {
        if (preg_match('/^[a-f0-9]{64}$/D', $identity) !== 1) {
            throw new RuntimeException('Некорректная идентичность восстановления БД');
        }
        $checkpoint = new UpdateStepCheckpoint(dirname($dump) . '/.restore-progress.json', $identity);
        $state = $checkpoint->read();
        $ledger = '__workspace_restore_' . substr($identity, 0, 24);
        foreach ($metadata['table_metadata'] ?? [] as $table) {
            if (($table['name'] ?? '') === $ledger) {
                throw new RuntimeException('Снимок БД пересекается со служебным журналом восстановления');
            }
        }
        if ($state === []) {
            $state = ['phase' => 'drop', 'cursor' => 0, 'objects' => $this->objects($db)];
            $checkpoint->write($state);
        }
        $db->query('SET NAMES utf8mb4');
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        // Ограничиваем ожидание чужой блокировки; таймаут сохраняет прогресс.
        $db->query('SET SESSION lock_wait_timeout=5');
        $db->query('SET SESSION innodb_lock_wait_timeout=5');
        try {
            if ($state['phase'] === 'drop') {
                foreach ($state['objects'] as $index => $object) {
                    if ($index < (int) $state['cursor']) continue;
                    $db->query('DROP ' . $object['type'] . ' IF EXISTS ' . $this->quote($object['name']));
                    $state['cursor'] = $index + 1;
                    $checkpoint->write($state);
                    $budget->checkpoint('rollback_database');
                }
                $state = ['phase' => 'sql'];
                $checkpoint->write($state);
            }
            if ($state['phase'] === 'sql') {
                $quotedLedger = $this->quote($ledger);
                $db->query('CREATE TABLE IF NOT EXISTS ' . $quotedLedger . ' ('
                    . 'id TINYINT PRIMARY KEY, identity_sha CHAR(64) NOT NULL, '
                    . 'byte_offset BIGINT UNSIGNED NOT NULL, delimiter_value VARCHAR(64) NOT NULL) ENGINE=InnoDB');
                $db->query("INSERT IGNORE INTO {$quotedLedger} VALUES (1,'{$identity}',0,';')");
                $position = $db->query("SELECT * FROM {$quotedLedger} WHERE id=1")->fetch_assoc();
                if (!is_array($position) || !hash_equals($identity, (string) $position['identity_sha'])) {
                    throw new RuntimeException('Журнал SQL относится к другому снимку БД');
                }
                $offset = (int) $position['byte_offset'];
                $delimiter = (string) $position['delimiter_value'];
                $pending = [$offset, $delimiter];
                foreach ($statements($offset, $delimiter, static function (int $end, string $nextDelimiter) use (&$pending): void {
                    $pending = [$end, $nextDelimiter];
                }) as $raw) {
                    $sql = $normalize($raw);
                    $isInsert = preg_match('/^INSERT\s+INTO\b/i', $sql) === 1;
                    try {
                        if ($isInsert) $db->begin_transaction();
                        // CREATE TABLE/TRIGGER мог завершиться до записи курсора.
                        // В этой точке данные объекта ещё не начинались.
                        if (preg_match('/^CREATE\s+(TABLE|TRIGGER)\s+(`(?:``|[^`])+`)/i', $sql, $match) === 1) {
                            $db->query('DROP ' . strtoupper($match[1]) . ' IF EXISTS ' . $match[2]);
                        }
                        $result = $db->query($sql);
                        if ($result instanceof mysqli_result) $result->free();
                        while ($db->more_results()) {
                            $db->next_result();
                            if ($result = $db->store_result()) $result->free();
                        }
                        $nextOffset = (int) $pending[0];
                        $nextDelimiter = $db->real_escape_string($pending[1]);
                        $db->query("UPDATE {$quotedLedger} SET byte_offset={$nextOffset}, delimiter_value='{$nextDelimiter}' WHERE id=1");
                        if ($isInsert) $db->commit();
                    } catch (Throwable $error) {
                        if ($isInsert) $db->rollback();
                        throw new RuntimeException('Ошибка шага восстановления БД; код '
                            . $error->getCode() . '; SHA-256 SQL ' . hash('sha256', $sql), 0, $error);
                    }
                    $budget->checkpoint('rollback_database');
                }
                // Сначала сохраняем завершение SQL, затем удаляем служебную таблицу.
                $state = ['phase' => 'verify'];
                $checkpoint->write($state);
            }
            $db->query('DROP TABLE IF EXISTS ' . $this->quote($ledger));
            return $verify();
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /** @return list<array{type:string,name:string}> */
    private function objects(mysqli $db): array
    {
        $objects = [];
        $queries = [
            'VIEW' => 'SELECT TABLE_NAME AS name FROM information_schema.views WHERE table_schema=DATABASE()',
            'EVENT' => 'SELECT EVENT_NAME AS name FROM information_schema.events WHERE event_schema=DATABASE()',
            'ROUTINE' => 'SELECT ROUTINE_NAME AS name, ROUTINE_TYPE AS kind FROM information_schema.routines WHERE routine_schema=DATABASE()',
            'TABLE' => "SELECT TABLE_NAME AS name FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_TYPE='BASE TABLE'",
        ];
        foreach ($queries as $type => $query) {
            $result = $db->query($query . ' ORDER BY name');
            while ($row = $result->fetch_assoc()) {
                $kind = $type === 'ROUTINE' ? strtoupper((string) $row['kind']) : $type;
                if (!in_array($kind, ['VIEW', 'EVENT', 'PROCEDURE', 'FUNCTION', 'TABLE'], true)) {
                    throw new RuntimeException('Неподдерживаемый объект БД при восстановлении');
                }
                $objects[] = ['type' => $kind, 'name' => (string) $row['name']];
            }
            $result->free();
        }
        return $objects;
    }

    private function quote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
