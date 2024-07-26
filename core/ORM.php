<?php

namespace Core;

use PDO;
use PDOException;

class ORM {
    protected $_tablename;
    protected array $columns = [];
    protected $where = '';
    protected $limit = '';
    protected $offset = '';
    protected $groupBy = '';
    protected $joins = [];
    protected $joinedModels = [];

    public function __construct() {
        //var_dump($this->_tablename);
    }

    public static function select(...$cols): static {
        $className = static::class;
        $classInstance = new $className();
        $classInstance->columns = $cols;
        return $classInstance;
    }

    public function get(): array {
        $columns = empty($this->columns) ? '*' : implode(', ', $this->columns);
        $sql = "SELECT {$columns} FROM {$this->_tablename}";
        var_dump($sql);
        return [];
    }
}
