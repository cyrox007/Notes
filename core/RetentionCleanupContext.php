<?php

declare(strict_types=1);

namespace Core;

/**
 * Общая инфраструктура: соединение, файловая безопасность и учёт.
 * SQL и правила хранения конкретных таблиц принадлежат модулям.
 */
final class RetentionCleanupContext
{
    /** @var array<string,bool> */
    private array $tableCache = [];

    public function __construct(
        public readonly DatabaseManager $db,
        private readonly RetentionFileCleanup $files
    ) {
    }

    public function hasTable(string $table): bool
    {
        if (isset($this->tableCache[$table])) {
            return $this->tableCache[$table];
        }
        $exists = $this->db->fetchValue(
            'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table LIMIT 1',
            [':table' => $table]
        ) !== null;
        return $this->tableCache[$table] = $exists;
    }

    /** @param list<string> $paths @param array<string,mixed> $result */
    public function removePaths(array $paths, array &$result): bool
    {
        return $this->files->removePaths($paths, $result);
    }

    /** @param array<string,mixed> $result */
    public function addPurged(array &$result, string $key, int $count): void
    {
        $result['purged'][$key] = (int) ($result['purged'][$key] ?? 0) + $count;
    }

    /**
     * Удаление простых записей с повторной проверкой срока и состояния.
     * Таблица и условие задаются владельцем и никогда не приходят от HTTP.
     *
     * @param array<string,mixed> $result
     */
    public function purgeSimpleRows(
        string $table,
        string $where,
        string $cutoff,
        int $limit,
        array &$result
    ): void {
        if (!$this->hasTable($table)) {
            return;
        }
        $rows = $this->db->fetchAll(
            'SELECT id FROM ' . $table . ' WHERE ' . $where . ' ORDER BY id ASC LIMIT ' . $limit,
            [':cutoff' => $cutoff]
        );
        $purged = 0;
        foreach ($rows as $row) {
            $purged += $this->db->execute(
                'DELETE FROM ' . $table . ' WHERE id = :id AND ' . $where,
                [':id' => (int) $row['id'], ':cutoff' => $cutoff]
            );
        }
        $this->addPurged($result, $table, $purged);
    }

    public function countRows(string $table, string $where, string $cutoff, int $limit): ?int
    {
        if (!$this->hasTable($table)) {
            return null;
        }
        return min($limit, (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where,
            [':cutoff' => $cutoff]
        ));
    }
}
