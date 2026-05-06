<?php

declare(strict_types=1);

namespace Core;

use PDO;
use PDOException;
use PDOStatement;
use Exception;

/**
 * Уровни логирования для DatabaseManager
 */
enum LogLevel: string {
    case DEBUG = 'DEBUG';
    case INFO = 'INFO';
    case WARNING = 'WARNING';
    case ERROR = 'ERROR';
}

/**
 * Менеджер соединений с базой данных с подробным логированием
 * 
 * Singleton класс для управления PDO соединениями.
 * Все операции логируются для упрощения отладки.
 * 
 * @phpstan-type TransactionType 'insert'|'update'|delete'
 * @phpstan-type QueryLogEntry array{timestamp: string, level: string, message: string}
 * @phpstan-type QueryStats array{total_queries: int, log_entries: int}
 * @phpstan-type TransactionResult array<int, array{type: TransactionType, affected_rows: int, last_insert_id: int|null, exec_time_ms: float}>|false
 */
class DatabaseManager {
    private static ?self $instance = null;
    private ?PDO $pdo = null;
    private array $transactionQueue = [];
    private bool $inTransaction = false;
    
    // Настройки логирования
    public readonly bool $enableLogging;
    public readonly int $maxLogLength;
    private int $queryCount = 0;
    private array $queryLog = [];
    
    /**
     * Конструктор с promoter properties
     */
    private function __construct(
        bool $enableLogging = true,
        int $maxLogLength = 100
    ) {
        $this->enableLogging = $enableLogging;
        $this->maxLogLength = $maxLogLength;
        
        $this->log("[DatabaseManager] Инициализация соединения", LogLevel::INFO);
        $this->pdo = $this->createConnection();
    }

    // Prevent cloning
    private function __clone() {}

    // Prevent unserialization
    public function __wakeup(): void {
        throw new Exception("Cannot unserialize singleton");
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Сброс экземпляра (для тестов или переподключения)
     */
    public static function resetInstance(): void {
        if (self::$instance !== null) {
            self::$instance->close();
            self::$instance = null;
        }
    }

    /**
     * Логирование событий БД
     */
    public function log(string $message, LogLevel|string $level = LogLevel::INFO): void {
        if (!$this->enableLogging) {
            return;
        }
        
        $levelString = $level instanceof LogLevel ? $level->value : $level;
        $timestamp = date('Y-m-d H:i:s.v');
        $truncatedMessage = strlen($message) > $this->maxLogLength 
            ? substr($message, 0, $this->maxLogLength) . '...' 
            : $message;
        
        $logMessage = "[{$timestamp}] [DB] [{$levelString}] {$truncatedMessage}";
        
        // Логируем в error_log
        error_log($logMessage);
        
        // Сохраняем в памяти для отладки (ограничиваем размер лога)
        $this->queryLog[] = [
            'timestamp' => $timestamp,
            'level' => $levelString,
            'message' => $message,
            'memory_usage' => memory_get_usage(true)
        ];
        
        // Очищаем старые записи если лог слишком большой
        if (count($this->queryLog) > 1000) {
            array_shift($this->queryLog);
        }
    }

    /**
     * Создает новое PDO соединение с оптимальными настройками
     */
    private function createConnection(): PDO {
        $config = Config::$db_connection;
        $driver = $config['driver'] ?? 'mysql';
        $dsn = $this->buildDsn($driver, $config);
        
        $this->log("[createConnection] Подключение к БД: {$driver}", LogLevel::INFO);
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 30,
            PDO::ATTR_PERSISTENT => false,
        ];

        if ($driver === 'mysql') {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
            $options[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
            $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
        }

        try {
            $pdo = new PDO(
                $dsn, 
                $config['username'] ?? '', 
                $config['password'] ?? '', 
                $options
            );
            $this->log("[createConnection] Соединение успешно установлено", LogLevel::INFO);
            return $pdo;
        } catch (PDOException $e) {
            $this->log("[createConnection] Ошибка подключения: " . $e->getMessage(), LogLevel::ERROR);
            throw $e;
        }
    }

    /**
     * Строит DSN строку в зависимости от драйвера
     */
    private function buildDsn(string $driver, array $config): string {
        return match($driver) {
            'mysql' => "mysql:host={$config['hostname']};port={$config['port']};dbname={$config['database']};charset=utf8mb4",
            'pgsql' => "pgsql:host={$config['hostname']};port={$config['port']};dbname={$config['database']}",
            'sqlite' => "sqlite:" . ($config['database'] ?? ':memory:'),
            default => throw new Exception("Unsupported database driver: {$driver}"),
        };
    }

    /**
     * Проверяет и при необходимости восстанавливает соединение
     */
    private function ensureConnection(): void {
        try {
            if ($this->pdo === null) {
                $this->log("[ensureConnection] Соединение отсутствует, создаем новое", LogLevel::WARNING);
                $this->pdo = $this->createConnection();
                return;
            }
            
            // Проверка жизнеспособности соединения
            $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (PDOException $e) {
            $this->log("[ensureConnection] Соединение разорвано, переподключение: " . $e->getMessage(), LogLevel::WARNING);
            $this->pdo = $this->createConnection();
        }
    }

    /**
     * Добавляет INSERT операцию в очередь транзакции
     * @param array<string, mixed> $data
     */
    public function queueInsert(array $data, string $table): self {
        $columns = array_keys($data);
        $placeholders = array_map(fn($col): string => ":{$col}", $columns);
        
        $query = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        $this->log("[queueInsert] Добавлено в очередь: {$table}, поля: " . implode(', ', $columns), LogLevel::DEBUG);
        
        $this->transactionQueue[] = [
            'type' => 'insert',
            'query' => $query,
            'parameters' => $data,
            'table' => $table
        ];
        
        return $this;
    }

    /**
     * Добавляет UPDATE операцию в очередь транзакции
     * @param array<string, mixed> $data
     * @param mixed $id
     */
    public function queueUpdate(array $data, string $table, mixed $id): self {
        $setClause = array_map(fn($col): string => "{$col} = :{$col}", array_keys($data));
        $data['id'] = $id;
        
        $query = "UPDATE {$table} SET " . implode(', ', $setClause) . " WHERE id = :id";
        
        $this->log("[queueUpdate] Добавлено в очередь: {$table}, ID: {$id}, поля: " . implode(', ', array_keys($data)), LogLevel::DEBUG);
        
        $this->transactionQueue[] = [
            'type' => 'update',
            'query' => $query,
            'parameters' => $data,
            'table' => $table
        ];
        
        return $this;
    }

    /**
     * Добавляет DELETE операцию в очередь транзакции
     * @param mixed $id
     */
    public function queueDelete(string $table, mixed $id): self {
        $this->log("[queueDelete] Добавлено в очередь: {$table}, ID: {$id}", LogLevel::DEBUG);
        
        $this->transactionQueue[] = [
            'type' => 'delete',
            'query' => "DELETE FROM {$table} WHERE id = :id",
            'parameters' => ['id' => $id],
            'table' => $table
        ];
        
        return $this;
    }

    /**
     * Выполняет все queued операции в транзакции
     * @return TransactionResult
     */
    public function commit(): array|false {
        if (empty($this->transactionQueue)) {
            $this->log("[commit] Очередь пуста, ничего не выполняем", LogLevel::DEBUG);
            return [];
        }

        $queueCount = count($this->transactionQueue);
        $this->log("[commit] Начало транзакции, операций в очереди: {$queueCount}", LogLevel::INFO);

        try {
            $this->ensureConnection();
            $this->pdo->beginTransaction();
            $this->inTransaction = true;

            $results = [];
            
            foreach ($this->transactionQueue as $index => $transaction) {
                $this->log("[commit] Выполнение операции #{$index}: {$transaction['type']} для {$transaction['table']}", LogLevel::DEBUG);
                
                $stmt = $this->pdo->prepare($transaction['query']);
                $startTime = microtime(true);
                
                if (!$stmt->execute($transaction['parameters'])) {
                    throw new PDOException(
                        "Transaction failed at step {$index}: " . implode(', ', $stmt->errorInfo())
                    );
                }
                
                $execTime = round((microtime(true) - $startTime) * 1000, 2);
                $this->log("[commit] Операция #{$index} выполнена за {$execTime}мс", LogLevel::DEBUG);

                $results[$index] = [
                    'type' => $transaction['type'],
                    'affected_rows' => $stmt->rowCount(),
                    'last_insert_id' => $transaction['type'] === 'insert' ? (int)$this->pdo->lastInsertId() : null,
                    'exec_time_ms' => $execTime
                ];
            }

            $this->pdo->commit();
            $this->inTransaction = false;
            $this->transactionQueue = [];

            $this->log("[commit] Транзакция успешно завершена", LogLevel::INFO);
            
            return $results;
            
        } catch (Exception $e) {
            if ($this->inTransaction && $this->pdo?->inTransaction()) {
                $this->pdo->rollBack();
                $this->inTransaction = false;
                $this->log("[commit] Откат транзакции из-за ошибки", LogLevel::ERROR);
            }
            
            $this->transactionQueue = [];
            
            $errorMsg = "Database transaction failed: " . $e->getMessage();
            $this->log($errorMsg, LogLevel::ERROR);
            
            return false;
        }
    }

    /**
     * Отменяет все queued операции
     */
    public function rollback(): void {
        $queueCount = count($this->transactionQueue);
        $this->log("[rollback] Отмена {$queueCount} операций в очереди", LogLevel::WARNING);
        
        $this->transactionQueue = [];
        if ($this->inTransaction && $this->pdo?->inTransaction()) {
            $this->pdo->rollBack();
            $this->inTransaction = false;
        }
    }

    /**
     * Выполняет один query вне транзакции
     * @param array<string, mixed> $params
     * @return PDOStatement|int
     */
    public function execute(string $query, array $params = []): PDOStatement|int {
        $this->ensureConnection();
        $this->queryCount++;
        
        $queryType = strtoupper(explode(' ', trim($query))[0]);
        $this->log("[execute] #{$this->queryCount} {$queryType}: " . $this->maskQuery($query, $params), LogLevel::DEBUG);
        
        $stmt = $this->pdo->prepare($query);
        $startTime = microtime(true);
        $stmt->execute($params);
        $execTime = round((microtime(true) - $startTime) * 1000, 2);
        
        $result = match($queryType) {
            'SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN' => $stmt,
            default => $stmt->rowCount(),
        };
        
        $this->log("[execute] Завершено за {$execTime}мс", LogLevel::DEBUG);
        
        return $result;
    }

    /**
     * Маскирует чувствительные данные в логах
     * @param array<string, mixed> $params
     */
    private function maskQuery(string $query, array $params): string {
        $maskedQuery = $query;
        foreach ($params as $key => $value) {
            $placeholder = is_numeric($key) ? '?' : (string)$key;
            $maskedValue = is_string($value) && strlen($value) > 20 
                ? substr($value, 0, 20) . '...' 
                : (string)$value;
            $maskedQuery = str_replace($placeholder, $maskedValue, $maskedQuery);
        }
        return $maskedQuery;
    }

    /**
     * Выполняет query и возвращает все результаты
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $query, array $params = []): array {
        $this->log("[fetchAll] Запрос данных", LogLevel::DEBUG);
        $stmt = $this->execute($query, $params);
        $result = $stmt instanceof PDOStatement ? $stmt->fetchAll() : [];
        $this->log("[fetchAll] Получено записей: " . count($result), LogLevel::INFO);
        return $result;
    }

    /**
     * Выполняет query и возвращает первую строку
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $query, array $params = []): ?array {
        $this->log("[fetchOne] Запрос одной записи", LogLevel::DEBUG);
        $stmt = $this->execute($query, $params);
        $result = $stmt instanceof PDOStatement ? $stmt->fetch() : null;
        return $result ?: null;
    }

    /**
     * Выполняет query и возвращает одно значение
     * @param array<string, mixed> $params
     */
    public function fetchValue(string $query, array $params = []): mixed {
        $this->log("[fetchValue] Запрос одного значения", LogLevel::DEBUG);
        $stmt = $this->execute($query, $params);
        $result = $stmt instanceof PDOStatement ? $stmt->fetchColumn() : null;
        return $result ?: null;
    }

    /**
     * Возвращает PDO инстанс для прямого использования
     */
    public function getPdo(): PDO {
        $this->ensureConnection();
        return $this->pdo;
    }

    /**
     * Начинает явную транзакцию (для сложных случаев)
     */
    public function beginTransaction(): bool {
        $this->ensureConnection();
        $this->log("[beginTransaction] Начало явной транзакции", LogLevel::INFO);
        $this->inTransaction = $this->pdo->beginTransaction();
        return $this->inTransaction;
    }

    /**
     * Завершает явную транзакцию
     */
    public function endTransaction(bool $commit = true): void {
        if (!$this->inTransaction) {
            $this->log("[endTransaction] Транзакция не активна", LogLevel::WARNING);
            return;
        }
        
        if ($commit) {
            $this->pdo->commit();
            $this->log("[endTransaction] Транзакция подтверждена", LogLevel::INFO);
        } else {
            $this->pdo->rollBack();
            $this->log("[endTransaction] Транзакция откачена", LogLevel::INFO);
        }
        
        $this->inTransaction = false;
    }

    /**
     * Очищает соединение (для пулинга)
     */
    public function close(): void {
        $this->log("[close] Закрытие соединения с БД", LogLevel::INFO);
        $this->pdo = null;
        $this->transactionQueue = [];
        $this->inTransaction = false;
    }

    /**
     * Возвращает статистику запросов
     * @return QueryStats
     */
    public function getQueryStats(): array {
        return [
            'total_queries' => $this->queryCount,
            'log_entries' => count($this->queryLog)
        ];
    }

    /**
     * Возвращает лог запросов
     * @return array<int, QueryLogEntry>
     */
    public function getQueryLog(): array {
        return $this->queryLog;
    }

    /**
     * Деструктор закрывает соединение
     */
    public function __destruct() {
        if ($this->inTransaction) {
            $this->log("[__destruct] Предупреждение: незавершенная транзакция при уничтожении", LogLevel::WARNING);
        }
        $this->close();
    }
}