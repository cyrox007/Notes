<?php

namespace Core;

use PDO;
use PDOException;
use Core\databaseControll;

class ORM {
    protected $_tablename;
    private array $columns = [];
    private $where = '';
    private $params = [];
    private $limit = '';
    private $offset = '';
    private $orderBy = '';
    private $joins = [];

    public function __construct() {
        
    }

    public static function select(...$cols): static {
        $className = static::class;
        $classInstance = new $className();
        $classInstance->columns = $cols;
        return $classInstance;
    }

    public function where(string $col, string $operator, string $value): self {
        $placeholder = ":{$col}";
        $this->where = "WHERE {$col} {$operator} {$placeholder}";
        $this->params[$placeholder] = $value;
        return $this;
    }

    public function whereAND(string $col, string $operator, string $value): self {
        $placeholder = ":{$col}";
        $this->where .= " AND {$col} {$operator} {$placeholder}";
        $this->params[$placeholder] = $value;
        return $this;
    }
    
    public function whereOR(string $col, string $operator, string $value): self {
        $placeholder = ":{$col}";
        $this->where .= " OR {$col} {$operator} {$placeholder}";
        $this->params[$placeholder] = $value;
        return $this;
    }

    public function innerJoin(array $model, string $on, string $operator, string $equals): self {
        [$modelClass, $modelName] = $model;
        
        $modelClassInctance = new $modelClass();
        $tableName = $modelClassInctance->_tablename;
        $this->joins[$modelName] = "INNER JOIN {$tableName} AS {$modelName} ON {$on} {$operator} {$equals}";
        return $this;
    }

    public function outerJoin(array $model, string $on, string $operator, string $equals): self {
        [$modelClass, $modelName] = $model;
        
        $modelClassInctance = new $modelClass();
        $tableName = $modelClassInctance->_tablename;
        $this->joins[] = "OUTER JOIN {$tableName} AS {$modelName} ON {$on} {$operator} {$equals}";
        return $this;
    }
    
    public function leftJoin(array $model, string $on, string $operator, string $equals): self {
        [$modelClass, $modelName] = $model;
        
        $modelClassInctance = new $modelClass();
        $tableName = $modelClassInctance->_tablename;
        $this->joins[] = "LEFT JOIN {$tableName} AS {$modelName} ON {$on} {$operator} {$equals}";
        return $this;
    }

    public function limit(int $limit): self {
        $this->limit = $limit;
        return $this;
    }
    
    public function offset(int $offset): self {
        $this->offset = $offset;
        return $this;
    }
    
    public function orderBy(string $col, string $by = "ASC"): self {
        $this->orderBy = "{$col} {$by}";
        return $this;
    }

    public function get(): array {
        $db = DatabaseControll::connect();

        $stmt = $db->prepare($this->queryBuilder());

        foreach ($this->params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $finalRes = [];
        foreach ($result as $row) {
            $finalRes[] = $this->mapRow($row);
        }

        return $finalRes;
    }

    public function first(): ?static {
        $db = DatabaseControll::connect();
    
        $stmt = $db->prepare($this->queryBuilder());
    
        foreach ($this->params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
    
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return $this->mapRow($result);
        }
        
        return null;
    }

    public function count(): int {
        $db = DatabaseControll::connect();
    
        $stmt = $db->prepare($this->queryBuilder());
    
        foreach ($this->params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
    
        $stmt->execute();
        $count = $stmt->rowCount();
        
        return $count;
    }

    private function queryBuilder(): string {
        $columns = [];
        foreach ($this->columns as $col) {
            $alias = str_replace('.', '_', $col);
            $columns[] = "{$col} AS {$alias}";
        }
        $columns = empty($this->columns) ? '*' : implode(', ', $columns);
        $sql = "SELECT {$columns} FROM {$this->_tablename}";

        if (!empty($this->joins)) {
            $sql.=' '. implode(' ', $this->joins);
        }

        if (!empty($this->where)) {
            $sql .= ' ' . $this->where;
        }

        if (!empty($this->orderBy)) {
            $sql .= ' ORDER BY ' . $this->orderBy;
        }

        if (!empty($this->limit)) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if (!empty($this->offset)) {
            $sql .= ' OFFSET ' . $this->offset;
        }
        
        return $sql;
    }

    private function mapRow(array $row): static {
        $object = new static();

        foreach ($row as $column => $value) {
            if (strpos($column, '_') === 0) {
                if (strpos($column, $this->_tablename) === 0) {
                    $columnName = str_replace($this->_tablename . '_', '', $column);
                    $object->$columnName = $value;
                } else {
                    [$table, $col] = explode('_', $column);
                    $object->$table->$col = $value;
                }
            } else {
                $object->$column = $value;
            }
        }

        return $object;
    }
}
