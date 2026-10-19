<?php

declare(strict_types=1);

namespace Core;

use PDO;
use PDOStatement;

/**
 * Выражение ядра: выполнение, учёт и диагностика находятся в DatabaseManager.
 * Не создаёт отдельного соединения и сохраняет блокировки текущей транзакции.
 */
final class DatabasePreparedStatement
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly PDOStatement $statement,
        private readonly string $sql
    ) {
    }

    public function execute(#[\SensitiveParameter] ?array $parameters = null): bool
    {
        $this->database->executePreparedStatement($this->statement, $this->sql, $parameters);
        return true;
    }

    public function fetch(?int $mode = null): mixed
    {
        return $mode === null ? $this->statement->fetch() : $this->statement->fetch($mode);
    }

    public function fetchAll(?int $mode = null): array
    {
        return $mode === null ? $this->statement->fetchAll() : $this->statement->fetchAll($mode);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->statement->fetchColumn($column);
    }

    public function rowCount(): int
    {
        return $this->statement->rowCount();
    }

    public function bindValue(string|int $parameter, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        return $this->statement->bindValue($parameter, $value, $type);
    }
}
