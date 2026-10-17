<?php
declare(strict_types=1);
namespace Core;
final class PrefixPDOStatement extends \PDOStatement
{
    protected function __construct(private readonly DatabasePrefix $namespace) {}
    public function execute(?array $params = null): bool { return parent::execute($this->namespace->parameters($this->queryString, $params)); }
    public function bindValue(string|int $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        $key = is_int($param) ? $param - 1 : $param;
        return parent::bindValue($param, $this->namespace->parameters($this->queryString, [$key => $value])[$key], $type);
    }
}
