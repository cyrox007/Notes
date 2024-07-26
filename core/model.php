<?php

namespace Core;

use PDO;
use PDOStatement;
use ReflectionClass;
use ReflectionProperty;
use Exception;

class Model {
    public $id;
    protected $_tablename;

    private string $query = "";
    private array $joins = [];
    private array $columns = [];
    private string $baseTable = "";
    private array $conditions = [];
    private array $parameters = [];
    private ?PDOStatement $preparedStmt = null;
    private ?int $limit = null;
    private ?int $offset = null;
    private ?string $order_by = null;

    private function reset(): void {
        $this->query = "";
        $this->joins = [];
        $this->columns = [];
        $this->baseTable = $this->_tablename;
        $this->conditions = [];
        $this->parameters = [];
        $this->preparedStmt = null;
        $this->limit = null;
        $this->offset = null;
        $this->order_by = null;
    }

    public function select(array $columns = [], ?string $alias = null): self {
        $this->reset();
        $aliasTable = $alias ?? $this->_tablename;
        $this->baseTable = $aliasTable;

        if (!empty($columns)) {
            foreach ($columns as $column) {
                $this->columns[] = "{$aliasTable}.{$column} AS {$aliasTable}_{$column}";
            }
        } else {
            $this->columns[] = "{$aliasTable}.*";
        }
        return $this;
    }
    
    public function innerJoin(
        string $table, 
        string $foreignKey, 
        string $primaryKey, 
        array $columns = [], 
        ?string $alias = null,
        string $operator = '='
    ): self {
        $aliasTable = $alias ?? $table;
    
        // Создание правильного ON условия и управление оператором сравнения
        $this->joins[] = "INNER JOIN {$table} AS {$aliasTable} ON {$this->baseTable}.{$foreignKey} {$operator} {$aliasTable}.{$primaryKey}";
    
        if (!empty($columns)) {
            foreach ($columns as $column) {
                $this->columns[] = "{$aliasTable}.{$column} AS {$aliasTable}_{$column}";
            }
        } else {
            $this->columns[] = "{$aliasTable}.*";
        }
    
        return $this;
    }

    public function leftJoin(
        string $table, 
        string $foreignKey, 
        string $primaryKey, 
        array $columns = [], 
        ?string $alias = null,
        string $operator = '='
    ): self {
        $aliasTable = $alias ?? $table;
    
        // Создание правильного ON условия и управление оператором сравнения
        $this->joins[] = "LEFT JOIN {$table} AS {$aliasTable} ON {$this->baseTable}.{$foreignKey} {$operator} {$aliasTable}.{$primaryKey}";
    
        if (!empty($columns)) {
            foreach ($columns as $column) {
                $this->columns[] = "{$aliasTable}.{$column} AS {$aliasTable}_{$column}";
            }
        } else {
            $this->columns[] = "{$aliasTable}.*";
        }
    
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

    public function order_by(string $table, string $by = "ASC"): self {
        $this->order_by = $table . ' ' . $by;
        
        return $this;
    }

    public function count(): int {
        // Ensure the query is built properly
        $this->buildQuery();

        // Modify the query to count the rows
        $countQuery = "SELECT COUNT(*) FROM ({$this->query}) as count_query";

        // Prepare and execute the count query
        $stmt = $this->connectDb()->prepare($countQuery);
        
        if ($stmt->execute($this->parameters)) {
            return (int) $stmt->fetchColumn();
        } else {
            error_log("Count query execution failed: " . implode(" ", $stmt->errorInfo()));
            throw new Exception("Failed to execute count query.");
        }
    }

    private function buildQuery(): void {
        if (empty($this->columns)) {
            throw new Exception("No columns specified for the SELECT query.");
        }
        
        // Собираем все столбцы в строку
        $cols = implode(', ', $this->columns);
        
        // Строим основной запрос SELECT
        $this->query = "SELECT {$cols} FROM {$this->_tablename} AS {$this->baseTable}";
        
        // Добавляем соединения JOIN
        foreach ($this->joins as $join) {
            $this->query .= ' ' . trim($join);
        }
        
        // Добавляем условия WHERE
        if (!empty($this->conditions)) {
            $this->query .= ' WHERE ' . implode(' ', $this->conditions);
        }
        
        // Добавляем LIMIT и OFFSET, если они заданы
        if ($this->limit !== null) {
            $this->query .= ' LIMIT ' . $this->limit;
        }
        
        if ($this->offset !== null) {
            $this->query .= ' OFFSET ' . $this->offset;
        }

        if ($this->order_by != null) {
            $this->query .= ' ORDER BY ' . $this->order_by;
        }
        
        // Логгируем финальный SQL запрос
        error_log("Generated SQL Query: {$this->query}");
        
        // Подготавливаем SQL выражение
        $this->preparedStmt = $this->connectDb()->prepare($this->query);
        
        // Проверяем, удалось ли подготовить выражение
        if ($this->preparedStmt === false) {
            throw new Exception("Failed to prepare the SQL statement.");
        }
    }

    public function where(string $column, string $operator, $parameter, string $logicalOperator = 'AND'): self {
        $placeholder = str_replace('.', '_', $column) . count($this->conditions);
        $condition = "{$column} {$operator} :{$placeholder}";
        
        if (!empty($this->conditions)) {
            $condition = "{$logicalOperator} {$condition}";
        }
        
        $this->conditions[] = $condition;
        $this->parameters[$placeholder] = $parameter;
        
        return $this;
    }

    protected function hydrate(array $data): self {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
        return $this;
    }

    public function first(bool $asObject = false): array|object|null {
        return $this->executeFetch(function ($stmt) use ($asObject) {
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($asObject) {
                return $data ? $this->hydrate($data) : null;
            }
            return $data ?: null;
        });
    }

    public function get(bool $asObjects = false): array|object {
        return $this->executeFetch(function ($stmt) use ($asObjects) {
            $rows = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($asObjects) {
                    $rows[] = (new static())->hydrate($row);
                } else {
                    $rows[] = $row;
                }
            }
            return $rows;
        });
    }

    private function executeFetch(callable $fetchFunction) {
        // Check if prepared statement is null and build query
        if ($this->preparedStmt === null) {
            $this->buildQuery();

            // Check if buildQuery successfully prepared the statement
            if ($this->preparedStmt === null) {
                // Log the error
                error_log("Failed to build query and prepare statement.");
                return null;
            }
        }

        // Execute the prepared statement with parameters
        if ($this->preparedStmt->execute($this->parameters)) {
            // Fetch results using the provided callback function
            return $fetchFunction($this->preparedStmt);
        } else {
            // Log the error if execution fails
            error_log("Statement execution failed: " . implode(" ", $this->preparedStmt->errorInfo()));
        }

        return null;
    }

    protected function getTableName(): string {
        if (isset($this->_tablename)) {
            return $this->_tablename;
        }
        return strtolower((new ReflectionClass($this))->getShortName()) . 's';
    }

    public function insert(): array {
        $reflectionClass = new ReflectionClass($this);
        $tablename = $this->getTableName();
        
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
        $tablename = $this->getTableName();
        
        if (!$tablename) {
            throw new Exception("Table name is not defined in the model.");
        }

        $updateFields = [];
        $parameters = [];

        // Loop through each public property of the model
        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyName = $property->getName();
            if ($propertyName != '_tablename' && property_exists($this, $propertyName)) {
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
        $tablename = $this->getTableName();
        
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

    
    // Метод соединения с БД
    private function connectDb(): PDO {
        $config = Config::$db_connection;
        $dsn = "mysql:host={$config['hostname']};port={$config['port']};dbname={$config['database']}";
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        return new PDO($dsn, $config['username'], $config['password'], $options);
    }
}