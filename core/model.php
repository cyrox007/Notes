<?php
namespace  Core;

use PDO;
use PDOStatement;
use ReflectionClass, ReflectionProperty;
use Exception;

class Model {
    public $id;
    /*
        Модель обычно включает методы выборки данных, это могут быть:
            > методы нативных библиотек pgsql или mysql;
            > методы библиотек, реализующих абстракицю данных. Например, методы библиотеки PEAR MDB2;
            > методы ORM;
            > методы для работы с NoSQL;
            > и др.
    */
    protected static $_tablename;

    private string $query = "";
    private array $joins = [];
    private array $columns = [];
    private string $baseTable = "";
    private array $conditions = [];
    private array $parameters = [];
    private ?PDOStatement $preparedStmt = null;

    public function select(string $tableName, array $columns = []): self {
        $this->baseTable = $tableName;
    
        if (!empty($columns)) {
            foreach ($columns as $column) {
                $this->columns[] = "{$tableName}.{$column}";
            }
        } else {
            $this->columns[] = "{$tableName}.*";
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

    private function buildQuery(): void {
        $cols = implode(', ', $this->columns);
        $this->query = "SELECT {$cols} FROM {$this->baseTable}";
    
        foreach ($this->joins as $join) {
            $this->query .= " " . $join . " ";
        }
    
        if (!empty($this->conditions)) {
            $this->query .= " WHERE " . implode(' ', $this->conditions);
        }
        
        $this->preparedStmt = $this->connectDb()->prepare($this->query);
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

    public function get(bool $asObjects = false) {
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
        if ($this->preparedStmt === null) {
            $this->buildQuery();
        }
    
        if ($this->preparedStmt->execute($this->parameters)) {
            return $fetchFunction($this->preparedStmt);
        }
        
        return null;
    }
    
    public function getTableName(): string {
        if (isset(static::$_tablename)) {
            return static::$_tablename;
        }
        return strtolower(static::class) . 's';
    }

    public function insert(): array {
        $reflectionClass = new ReflectionClass($this);
        $tablename = $this->getTableName();
        $columns = [];
        $values = [];
        $parameters = [];

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

        return [
            'query' => $query,
            'parameters' => $parameters
        ];
    }

    public function update(): array {
        $reflectionClass = new ReflectionClass($this);
        $tablename = $this->getTableName();
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
        
    // метод для получения поля таблицы. 
    // Принимает параметры: Открытая БД, таблицу откуда получаем, параметр поиска, значение поиска
    public function get_data($db, $table, $param, $value) {
        $sql = "SELECT * FROM {$table} WHERE {$param} = '{$value}'";
        $result = $db->query($sql);
        $row = $result->fetch();
        
        return $row;
    }

    // Метод для добавления поля в таблицу массива данных
    // Принимает параметры: Открытая БД, таблица, 
    // массив значений, который будет внесен в БД
    // key => value
    public function insert_data($db, $table, $array_data) {
        $imploded_key = []; // это строка в которую будем собирать параметры для изменения 
        $imploded_value = []; // это строка бует собирать их значения
        
        foreach ($array_data as $key => $value) { // разбираем массив
            $imploded_key[] = "$key"; // запишем ключи массива как значение массива
            $imploded_value[] = "'$value'"; // запишем отдельно значения массива
        }

        $string_parametrs_key = implode(", ", $imploded_key); // преобразовываем в строку
        $string_parametrs_value = implode(", ", $imploded_value); 

        // формируем запрос к базе данных
        $sql = "INSERT INTO {$table} ({$string_parametrs_key}) VALUES ({$string_parametrs_value})";
        $db->query($sql);
    }
    
    // Обновление поля таблицы:
    // Принимает параметры: Открытая БД, таблица, параметр поиска, 
    // значение поиска, массив значений, который будет внесен
    // key => value
    public function update_data($db, $table, $where_param, $where_value, $array) {
        $imploded = []; // это строка в которую будем собирать параметры для изменения и значения
        foreach ($array as $key => $value) { // разбираем массив
            $imploded[] = "$key = '$value'";
        }
        
        $string_parametrs = implode(", ", $imploded);
        $string_parametrs = str_replace('"', '', $string_parametrs); // почистим от кавычек
        
        $sql = "UPDATE {$table} SET {$string_parametrs} WHERE {$where_param} = {$where_value}";
        $db->query($sql);
    }

    // функция удаления позиции из БД
    public function delete_data($db, $table, $where_param, $where_value) {
        $sql = "DELETE FROM {$table} WHERE {$where_param} = {$where_value}";
        $db->query($sql);
    }
}