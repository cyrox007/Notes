<?php

namespace Core;

use PDO;
use PDOStatement;
use ReflectionClass;
use ReflectionProperty;
use Exception;

class Model
{
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
    }

    public function select(array $columns = []): self {
        $this->reset();

        $this->baseTable = $this->_tablename;

        if (!empty($columns)) {
            foreach ($columns as $column) {
                $this->columns[] = "{$this->_tablename}.{$column}";
            }
        } else {
            $this->columns[] = "{$this->_tablename}.*";
        }

        return $this;
    }
    
    public function innerJoin(string $table, string $primaryKey, string $foreignKey, array $columns = []): self {
        $this->joins[] = "INNER JOIN {$table} AS {$table} ON {$table}.{$foreignKey} = {$primaryKey}";

        if (!empty($columns)) {
            foreach ($columns as $column) {
                $this->columns[] = "{$table}.{$column}";
            }
        } else {
            $this->columns[] = "{$table}.*";
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
        // Ensure at least one column is specified for the SELECT query
        if (empty($this->columns)) {
            throw new Exception("No columns specified for the SELECT query.");
        }

        // Start building the query with selected columns and base table
        $cols = implode(', ', $this->columns);
        $this->query = "SELECT {$cols} FROM {$this->baseTable}";

        // Include any specified joins
        foreach ($this->joins as $join) {
            $this->query .= ' ' . trim($join);
        }

        // Add conditions if any
        if (!empty($this->conditions)) {
            $this->query .= ' WHERE ' . implode(' AND ', $this->conditions);
        }

        // Log the query for debugging purposes
        error_log("Generated SQL Query: {$this->query}");

        // Attempt to prepare the SQL statement
        $this->preparedStmt = $this->connectDb()->prepare($this->query);

        // Check the prepared statement
        if ($this->preparedStmt === false) {
            throw new Exception("Failed to prepare the SQL statement.");
        }
    }

    public function where(string $column, string $operator, $parameter, string $logicalOperator = 'AND'): self {
        $placeholder = str_replace('.', '_', $column) . count($this->conditions);
        $condition = "{$column} {$operator} :{$placeholder}";
        
        if (!empty($this->conditions)) {
            $condition = "{$logicalOperator} {$condition}";
        } else {
            // If this is the first condition, no logical operator is needed
            $condition = "{$column} {$operator} :{$placeholder}";
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