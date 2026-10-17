<?php
declare(strict_types=1);
namespace Core;
require_once __DIR__ . '/DatabasePrefix.php';
require_once __DIR__ . '/PrefixPDOStatement.php';

final class PrefixPDO extends \PDO
{
    public readonly DatabasePrefix $namespace;
    public function __construct(string $dsn, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?array $options = null)
    {
        $this->namespace = new DatabasePrefix();
        $options ??= [];
        $options[\PDO::ATTR_STATEMENT_CLASS] = [PrefixPDOStatement::class, [$this->namespace]];
        parent::__construct($dsn, $username, $password, $options);
    }
    public function prepare(string $query, array $options = []): \PDOStatement|false { return parent::prepare($this->namespace->sql($query), $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        return $fetchMode === null ? parent::query($this->namespace->sql($query)) : parent::query($this->namespace->sql($query), $fetchMode, ...$fetchModeArgs);
    }
    public function exec(string $statement): int|false { return parent::exec($this->namespace->sql($statement)); }
}
