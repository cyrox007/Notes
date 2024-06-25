<?php
namespace  Core;

use PDO;
use PDOStatement;

class Model {
    /*
        Модель обычно включает методы выборки данных, это могут быть:
            > методы нативных библиотек pgsql или mysql;
            > методы библиотек, реализующих абстракицю данных. Например, методы библиотеки PEAR MDB2;
            > методы ORM;
            > методы для работы с NoSQL;
            > и др.
    */

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

    public function first(): ?array {
        return $this->executeFetch(function ($stmt) {
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        });
    }

    public function get() {
        return $this->executeFetch(function ($stmt) {
            $rows = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = $row;
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