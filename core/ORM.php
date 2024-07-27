<?php

namespace Core;

use Exception;
use PDO;
use PDOException;
use ReflectionClass;
use ReflectionProperty;
use Core\DatabaseControll;

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

    public function where(string $col, string $operator, string $value): static {
        $placeholder = strpos($col, '.') !== false ? ':'.str_replace('.', "_", $col) : ":{$col}";
        $this->where = "WHERE {$col} {$operator} {$placeholder}";
        $this->params[$placeholder] = $value;
        return $this;
    }

    public function whereAND(string $col, string $operator, string $value): static {
        $placeholder = strpos($col, '.') !== false ? ':'.str_replace('.', "_", $col) : ":{$col}";
        $this->where .= " AND {$col} {$operator} {$placeholder}";
        $this->params[$placeholder] = $value;
        return $this;
    }
    
    public function whereOR(string $col, string $operator, string $value): static {
        $placeholder = strpos($col, '.') !== false ? ':'.str_replace('.', "_", $col) : ":{$col}";
        $this->where .= " OR {$col} {$operator} {$placeholder}";
        $this->params[$placeholder] = $value;
        return $this;
    }

    public function innerJoin(array $model, string $on, string $operator, string $equals): static {
        [$modelClass, $modelName] = $model;
        
        $modelClassInctance = new $modelClass();
        $tableName = $modelClassInctance->_tablename;
        $this->joins[$modelName] = "INNER JOIN {$tableName} AS {$modelName} ON {$on} {$operator} {$equals}";
        return $this;
    }

    public function outerJoin(array $model, string $on, string $operator, string $equals): static {
        [$modelClass, $modelName] = $model;
        
        $modelClassInctance = new $modelClass();
        $tableName = $modelClassInctance->_tablename;
        $this->joins[] = "OUTER JOIN {$tableName} AS {$modelName} ON {$on} {$operator} {$equals}";
        return $this;
    }
    
    public function leftJoin(array $model, string $on, string $operator, string $equals): static {
        [$modelClass, $modelName] = $model;
        
        $modelClassInctance = new $modelClass();
        $tableName = $modelClassInctance->_tablename;
        $this->joins[] = "LEFT JOIN {$tableName} AS {$modelName} ON {$on} {$operator} {$equals}";
        return $this;
    }

    public function limit(int $limit): static {
        $this->limit = $limit;
        return $this;
    }
    
    public function offset(int $offset): static {
        $this->offset = $offset;
        return $this;
    }
    
    public function orderBy(string $col, string $by = "ASC"): static {
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
        if (empty($this->columns) && !empty($this->joins)) {
            throw new Exception('Columns must be specified when using JOIN');
        }
        
        $columns = array_map(function ($col) {
            $alias = str_replace('.', '__', $col);
            return "{$col} AS {$alias}";
        }, $this->columns);
        
        $columns = empty($columns) ? '*' : implode(', ', $columns);
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
        //var_dump($sql);
        return $sql;
    }


    private function mapRow(array $row): static {
        $object = new static();
        foreach ($row as $column => $value) {
            
            if (strpos($column, '__') !== false) {
                if (strpos($column, $this->_tablename) === 0) {
                    $columnName = str_replace($this->_tablename . '__', '', $column);
                    $object->$columnName = $value;
                } else {
                    [$table, $col] = explode('__', $column);
                    $object->$table->$col = $value;
                }
            } else {
                $object->$column = $value;
            }
        }

        return $object;
    }

    public function insert(): array {
        $reflectionClass = new ReflectionClass($this);
        $tablename = $this->_tablename;
        
        // Проверка наличия имени таблицы
        if (!$tablename) {
            throw new Exception("Table name is not defined in the model.");
        }

        $columns = [];
        $values = [];
        $parameters = [];

        // Loop through each public property of the model
        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyName = $property->getName();
            if ($propertyName != '_tablename') {
                $columns[] = $propertyName;
                $values[] = ":$propertyName";
                $parameters[$propertyName] = $this->$propertyName;
            }
        }

        $columnNames = implode(',', $columns);
        $valuePlaceholders = implode(',', $values);
        $query = "INSERT INTO $tablename ($columnNames) VALUES ($valuePlaceholders)";

        // Логирование для дебага
        error_log("Generated SQL Query: $query");
        error_log("Parameters: " . print_r($parameters, true));

        return [
            'query' => $query,
            'parameters' => $parameters
        ];
    }


    public function update(): array {
        $reflectionClass = new ReflectionClass($this);
        $tablename = $this->_tablename;
        
        if (!$tablename) {
            throw new Exception("Table name is not defined in the model.");
        }

        $updateFields = [];
        $parameters = [];

        // Loop through each public property of the model
        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyName = $property->getName();
            if ($propertyName != '_tablename' && property_exists($this, $propertyName) && !is_object($this->$propertyName)) {
                $updateFields[] = "$propertyName = :$propertyName";
                $parameters[$propertyName] = $this->$propertyName;
            }
        }

        // Assuming there's an 'id' property to identify the record
        $parameters['id'] = $this->id;

        $updateFieldsStr = implode(', ', $updateFields);
        $query = "UPDATE $tablename SET $updateFieldsStr WHERE id = :id";

        // Логирование для дебага
        error_log("Generated SQL Query: $query");
        error_log("Parameters: " . print_r($parameters, true));

        return [
            'query' => $query,
            'parameters' => $parameters
        ];
    }

    public function delete(): array {
        // Получение имени таблицы
        $tablename = $this->_tablename;
        
        // Проверка наличия имени таблицы
        if (!$tablename) {
            throw new Exception("Table name is not defined in the model.");
        }

        // Предполагаем, что свойство 'id' идентифицирует запись
        $parameters = ['id' => $this->id];

        // Формирование SQL-запроса
        $query = "DELETE FROM $tablename WHERE id = :id";

        // Логирование для дебага
        error_log("Generated SQL Query: $query");
        error_log("Parameters: " . print_r($parameters, true));

        return [
            'query' => $query,
            'parameters' => $parameters
        ];
    }
}
