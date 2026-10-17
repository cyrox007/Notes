<?php
declare(strict_types=1);
namespace Core;
final class PrefixPDOStatement extends \PDOStatement
{
    protected function __construct(private readonly DatabasePrefix $namespace) {}
    public function execute(?array $params = null): bool { return parent::execute($this->namespace->parameters($this->queryString, $params)); }
    public function bindValue(string|int $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        return parent::bindValue($param, $this->namespace->parameters($this->queryString, [$value])[0], $type);
    }
}
