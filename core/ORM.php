<?php

declare(strict_types=1);

namespace Core;

use PDO;
use PDOException;
use Exception;
use ReflectionClass;
use ReflectionProperty;

/**
 * Уровни логирования для ORM (алиас на DatabaseManager\LogLevel)
 */
enum ORMLogLevel: string {
    case DEBUG = 'DEBUG';
    case INFO = 'INFO';
    case WARNING = 'WARNING';
    case ERROR = 'ERROR';
}

/**
 * Оптимизированный ORM класс с подробным логированием
 * 
 * Основные возможности:
 * - Кэширование соединений
 * - Prepared statements с правильным bind
 * - Поддержка транзакций через DatabaseManager
 * - Улучшенная обработка JOIN'ов
 * - Типизация параметров
 * - Полное логирование всех операций
 * 
 * @phpstan-type JoinDefinition array{0: class-string, 1: string}
 */
abstract class ORM {
    protected ?string $_tablename = null;
    protected int $id = 0;
    
    // Внутреннее состояние запроса
    private array $columns = [];
    private array $whereConditions = [];
    private array $params = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private ?string $orderBy = null;
    private ?string $groupBy = null;
    private array $joins = [];
    
    // Кэш для избежания повторных подключений
    private static ?PDO $connectionCache = null;
    
    // Счетчик запросов для отладки
    private static int $queryCounter = 0;

    /**
     * Конструктор
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = []) {
        if (!empty($data)) {
            $this->hydrate($data);
        }
    }

    /**
     * Логирование ORM операций
     */
    private function log(string $message, ORMLogLevel|string $level = ORMLogLevel::INFO): void {
        $levelString = $level instanceof ORMLogLevel ? $level->value : $level;
        $timestamp = date('Y-m-d H:i:s.v');
        $logMessage = "[{$timestamp}] [ORM] [{$levelString}] {$message}";
        error_log($logMessage);
    }

    /**
     * Статический метод для начала выборки
     * @return static
     */
    public static function select(string ...$cols): static {
        $instance = new static();
        $instance->columns = $cols;
        $instance->log("[select] Начало выборки из {$instance->_tablename}, поля: " . implode(', ', $cols), ORMLogLevel::INFO);
        return $instance;
    }

    /**
     * Добавляет WHERE условие
     */
    public function where(string $col, string $operator, mixed $value): static {
        $placeholder = $this->createPlaceholder($col);
        $this->whereConditions[] = "WHERE {$col} {$operator} {$placeholder}";
        $this->params[$placeholder] = $value;
        $this->log("[where] Добавлено условие: {$col} {$operator} ?", ORMLogLevel::DEBUG);
        return $this;
    }

    /**
     * Добавляет AND условие
     */
    public function whereAND(string $col, string $operator, mixed $value): static {
        $placeholder = $this->createPlaceholder($col);
        $this->whereConditions[] = "AND {$col} {$operator} {$placeholder}";
        $this->params[$placeholder] = $value;
        $this->log("[whereAND] Добавлено AND условие: {$col} {$operator} ?", ORMLogLevel::DEBUG);
        return $this;
    }

    /**
     * Добавляет OR условие
     */
    public function whereOR(string $col, string $operator, mixed $value): static {
        $placeholder = $this->createPlaceholder($col);
        $this->whereConditions[] = "OR {$col} {$operator} {$placeholder}";
        $this->params[$placeholder] = $value;
        $this->log("[whereOR] Добавлено OR условие: {$col} {$operator} ?", ORMLogLevel::DEBUG);
        return $this;
    }

    /**
     * Создает уникальный placeholder для параметра
     */
    private function createPlaceholder(string $col): string {
        $baseName = strpos($col, '.') !== false ? str_replace('.', '_', $col) : $col;
        return ':' . $baseName . '_' . count($this->params);
    }

    /**
     * Добавляет INNER JOIN
     * @param JoinDefinition $model
     */
    public function innerJoin(array $model, string $on, string $operator, string $equals): static {
        [$modelClass, $modelName] = $model;
        $tableName = (new $modelClass())->_tablename;
        $this->joins[$modelName] = "INNER JOIN {$tableName} AS {$modelName} ON {$on} {$operator} {$equals}";
        $this->log("[innerJoin] Добавлен INNER JOIN: {$tableName} AS {$modelName}", ORMLogLevel::DEBUG);
        return $this;
    }

    /**
     * Добавляет LEFT JOIN
     * @param JoinDefinition $model
     */
    public function leftJoin(array $model, string $on, string $operator, string $equals): static {
        [$modelClass, $modelName] = $model;
        $tableName = (new $modelClass())->_tablename;
        $this->joins[] = "LEFT JOIN {$tableName} AS {$modelName} ON {$on} {$operator} {$equals}";
        $this->log("[leftJoin] Добавлен LEFT JOIN: {$tableName} AS {$modelName}", ORMLogLevel::DEBUG);
        return $this;
    }

    /**
     * Устанавливает LIMIT
     */
    public function limit(int $limit): static {
        $this->limit = $limit;
        $this->log("[limit] Установлен LIMIT: {$limit}", ORMLogLevel::DEBUG);
        return $this;
    }

    /**
     * Устанавливает OFFSET
     */
    public function offset(int $offset): static {
        $this->offset = $offset;
        $this->log("[offset] Установлен OFFSET: {$offset}", ORMLogLevel::DEBUG);
        return $this;
    }

    /**
     * Устанавливает ORDER BY
     */
    public function orderBy(string $col, string $direction = 'ASC'): static {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orderBy = "{$col} {$direction}";
        $this->log("[orderBy] Установлен ORDER BY: {$col} {$direction}", ORMLogLevel::DEBUG);
        return $this;
    }

    /**
     * Устанавливает GROUP BY
     */
    public function groupBy(string $col): static {
        $this->groupBy = $col;
        $this->log("[groupBy] Установлен GROUP BY: {$col}", ORMLogLevel::DEBUG);
        return $this;
    }

    /**
     * Выполняет SELECT запрос и возвращает массив результатов
     * @return array<int, static>
     */
    public function get(): array {
        self::$queryCounter++;
        $queryId = self::$queryCounter;
        
        $sql = $this->buildQuery();
        $this->log("[get] #{$queryId} Выполнение запроса к {$this->_tablename}", ORMLogLevel::INFO);
        $this->log("[get] #{$queryId} SQL: " . $this->maskSql($sql), ORMLogLevel::DEBUG);
        
        $db = $this->getConnection();
        $stmt = $db->prepare($sql);
        
        foreach ($this->params as $key => $value) {
            $stmt->bindValue($key, $value, $this->getParamType($value));
        }

        $startTime = microtime(true);
        $stmt->execute();
        $execTime = round((microtime(true) - $startTime) * 1000, 2);
        
        $results = array_map(
            fn($row) => $this->mapRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
        
        $this->log("[get] #{$queryId} Получено записей: " . count($results) . ", время: {$execTime}мс", ORMLogLevel::INFO);
        
        return $results;
    }

    /**
     * Возвращает первую запись
     * @return static|null
     */
    public function first(): ?static {
        self::$queryCounter++;
        $queryId = self::$queryCounter;
        
        $sql = $this->buildQuery();
        $this->log("[first] #{$queryId} Поиск первой записи в {$this->_tablename}", ORMLogLevel::INFO);
        
        $db = $this->getConnection();
        $stmt = $db->prepare($sql);
        
        foreach ($this->params as $key => $value) {
            $stmt->bindValue($key, $value, $this->getParamType($value));
        }
        
        $startTime = microtime(true);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $execTime = round((microtime(true) - $startTime) * 1000, 2);
        
        if ($result) {
            $this->log("[first] #{$queryId} Запись найдена за {$execTime}мс", ORMLogLevel::INFO);
            return $this->mapRow($result);
        }
        
        $this->log("[first] #{$queryId} Запись не найдена за {$execTime}мс", ORMLogLevel::DEBUG);
        return null;
    }

    /**
     * Возвращает количество записей
     */
    public function count(): int {
        self::$queryCounter++;
        $queryId = self::$queryCounter;
        
        $countSql = "SELECT COUNT(*) FROM {$this->_tablename}";
        
        if (!empty($this->whereConditions)) {
            $countSql .= ' ' . implode(' ', $this->whereConditions);
        }
        
        $this->log("[count] #{$queryId} Подсчет записей в {$this->_tablename}", ORMLogLevel::INFO);
        
        $db = $this->getConnection();
        $stmt = $db->prepare($countSql);
        
        foreach ($this->params as $key => $value) {
            $stmt->bindValue($key, $value, $this->getParamType($value));
        }
        
        $startTime = microtime(true);
        $stmt->execute();
        $count = (int) $stmt->fetchColumn();
        $execTime = round((microtime(true) - $startTime) * 1000, 2);
        
        $this->log("[count] #{$queryId} Найдено записей: {$count}, время: {$execTime}мс", ORMLogLevel::INFO);
        
        return $count;
    }

    /**
     * Строит SQL запрос
     */
    private function buildQuery(): string {
        // Формируем список колонок
        $columns = $this->columns;
        if (!empty($columns) && !empty($this->joins)) {
            $columns = array_map(function ($col) {
                if (strpos($col, '.') !== false) {
                    $alias = str_replace(['.', ' '], ['__', '_'], $col);
                    return "{$col} AS {$alias}";
                }
                return $col;
            }, $columns);
        }
        
        $columnStr = empty($columns) ? '*' : implode(', ', $columns);
        $sql = "SELECT {$columnStr} FROM {$this->_tablename}";

        // Добавляем JOIN'ы
        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        // Добавляем WHERE
        if (!empty($this->whereConditions)) {
            $sql .= ' ' . implode(' ', $this->whereConditions);
        }

        // Добавляем GROUP BY
        if ($this->groupBy !== null) {
            $sql .= ' GROUP BY ' . $this->groupBy;
        }

        // Добавляем ORDER BY
        if ($this->orderBy !== null) {
            $sql .= ' ORDER BY ' . $this->orderBy;
        }

        // Добавляем LIMIT и OFFSET
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /**
     * Маппинг строки результата в объект
     */
    private function mapRow(array $row): static {
        $object = new static();
        
        foreach ($row as $column => $value) {
            if (strpos($column, '__') !== false) {
                if (strpos($column, $this->_tablename . '__') === 0) {
                    // Колонка из основной таблицы
                    $columnName = substr($column, strlen($this->_tablename) + 2);
                    $object->$columnName = $value;
                } else {
                    // Колонка из JOIN'нутой таблицы
                    [$table, $col] = explode('__', $column, 2);
                    if (!isset($object->$table)) {
                        $object->$table = new \stdClass();
                    }
                    $object->$table->$col = $value;
                }
            } else {
                $object->$column = $value;
            }
        }
        
        return $object;
    }

    /**
     * Гидратация объекта данными
     */
    public function hydrate(array $data): static {
        $this->log("[hydrate] Гидратация объекта " . static::class . " полями: " . implode(', ', array_keys($data)), 'DEBUG');
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
        return $this;
    }

    /**
     * Подготавливает данные для INSERT
     */
    public function insert(): array {
        if (!$this->_tablename) {
            throw new Exception("Table name is not defined in the model.");
        }

        $columns = [];
        $values = [];
        $parameters = [];

        $reflection = new ReflectionClass($this);
        
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            
            // Пропускаем служебные поля и объекты
            if ($name === '_tablename' || is_object($this->$name)) {
                continue;
            }
            
            $columns[] = $name;
            $values[] = ":{$name}";
            $parameters[$name] = $this->$name;
        }

        if (empty($columns)) {
            throw new Exception("No fields to insert.");
        }

        $query = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $this->_tablename,
            implode(',', $columns),
            implode(',', $values)
        );
        
        $this->log("[insert] Подготовлен INSERT в {$this->_tablename}: " . implode(', ', $columns));

        return ['query' => $query, 'parameters' => $parameters];
    }

    /**
     * Подготавливает данные для UPDATE
     */
    public function update(): array {
        if (!$this->_tablename) {
            throw new Exception("Table name is not defined in the model.");
        }

        $updateFields = [];
        $parameters = [];

        $reflection = new ReflectionClass($this);
        
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            
            // Пропускаем служебные поля и id (он идет в WHERE)
            if ($name === '_tablename' || $name === 'id' || is_object($this->$name)) {
                continue;
            }
            
            $updateFields[] = "{$name} = :{$name}";
            $parameters[$name] = $this->$name;
        }

        if (empty($updateFields)) {
            throw new Exception("No fields to update.");
        }

        $parameters['id'] = $this->id;
        
        $query = sprintf(
            "UPDATE %s SET %s WHERE id = :id",
            $this->_tablename,
            implode(', ', $updateFields)
        );
        
        $this->log("[update] Подготовлен UPDATE в {$this->_tablename} WHERE id={$this->id}: " . implode(', ', array_keys($parameters)));

        return ['query' => $query, 'parameters' => $parameters];
    }

    /**
     * Подготавливает данные для DELETE
     */
    public function delete(): array {
        if (!$this->_tablename) {
            throw new Exception("Table name is not defined in the model.");
        }
        
        $this->log("[delete] Подготовлен DELETE из {$this->_tablename} WHERE id={$this->id}");

        return [
            'query' => "DELETE FROM {$this->_tablename} WHERE id = :id",
            'parameters' => ['id' => $this->id]
        ];
    }

    /**
     * Получает соединение с БД (с кэшированием)
     */
    private function getConnection(): PDO {
        if (self::$connectionCache === null) {
            $this->log("[getConnection] Создание нового соединения с БД");
            self::$connectionCache = DatabaseControll::connect();
        } else {
            $this->log("[getConnection] Использование кэшированного соединения", 'DEBUG');
        }
        return self::$connectionCache;
    }

    /**
     * Определяет тип параметра для bindValue
     */
    private function getParamType(mixed $value): int {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            is_null($value) => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    /**
     * Сохраняет объект (INSERT или UPDATE)
     */
    public function save(): bool {
        $operation = $this->id > 0 ? 'UPDATE' : 'INSERT';
        $this->log("[save] Сохранение объекта " . static::class . " ({$operation})");
        
        $dbManager = DatabaseManager::getInstance();
        
        if ($this->id > 0) {
            $data = $this->update();
            $dbManager->queueUpdate($data['parameters'], $this->_tablename, $this->id);
        } else {
            $data = $this->insert();
            $dbManager->queueInsert($data['parameters'], $this->_tablename);
        }
        
        $result = $dbManager->commit();
        
        if ($result === false) {
            $this->log("[save] Ошибка сохранения", 'ERROR');
            return false;
        }
        
        $this->log("[save] Успешное сохранение");
        return true;
    }

    /**
     * Удаляет объект
     */
    public function remove(): bool {
        if ($this->id <= 0) {
            $this->log("[remove] Нельзя удалить объект с id <= 0", 'WARNING');
            return false;
        }
        
        $this->log("[remove] Удаление объекта " . static::class . " с id={$this->id}");
        
        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueDelete($this->_tablename, $this->id);
        
        $result = $dbManager->commit();
        
        if ($result === false) {
            $this->log("[remove] Ошибка удаления", 'ERROR');
            return false;
        }
        
        $this->log("[remove] Успешное удаление");
        return true;
    }
    
    /**
     * Возвращает статистику запросов ORM
     */
    public static function getQueryStats(): int {
        return self::$queryCounter;
    }
    
    /**
     * Маскирует SQL для логирования
     */
    private function maskSql(string $sql): string {
        // Можно добавить дополнительную обработку для чувствительных данных
        return $sql;
    }
}
