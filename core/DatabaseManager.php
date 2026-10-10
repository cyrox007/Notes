<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/DatabaseSqlInspector.php';
require_once __DIR__ . '/ServiceLog.php';

use Exception;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

enum LogLevel: string
{
    case DEBUG = 'DEBUG';
    case INFO = 'INFO';
    case WARNING = 'WARNING';
    case ERROR = 'ERROR';
}

/**
 * Компактный менеджер PDO для старых контроллеров и нового сервисного слоя.
 *
 * Публичный API сохраняет совместимость с предыдущей реализацией.
 */
class DatabaseManager
{
    private static ?self $instance = null;
    private ?PDO $pdo = null;

    /** @var list<array{type:string,query:string,parameters:array,table:string}> */
    private array $transactionQueue = [];

    private bool $inTransaction = false;
    private int $queryCount = 0;
    private array $queryLog = [];

    public readonly bool $enableLogging;
    public readonly int $maxLogLength;

    private function __construct(bool $enableLogging = true, int $maxLogLength = 100)
    {
        $this->enableLogging = $enableLogging;
        $this->maxLogLength = $maxLogLength;
        $this->log('[DatabaseManager] Инициализация соединения', LogLevel::INFO);
        $this->pdo = $this->createConnection();
    }

    private function __clone() {}

    public function __wakeup(): void
    {
        throw new Exception('Нельзя десериализовать singleton DatabaseManager');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function resetInstance(): void
    {
        if (self::$instance === null) {
            return;
        }

        self::$instance->close();
        self::$instance = null;
    }

    public function log(string $message, LogLevel|string $level = LogLevel::INFO): void
    {
        if (!$this->enableLogging) {
            return;
        }

        $levelString = $level instanceof LogLevel ? $level->value : $level;
        $timestamp = date('Y-m-d H:i:s.v');
        $truncated = strlen($message) > $this->maxLogLength
            ? substr($message, 0, $this->maxLogLength) . '...'
            : $message;

        error_log("[{$timestamp}] [DB] [{$levelString}] {$truncated}");

        $this->queryLog[] = [
            'timestamp' => $timestamp,
            'level' => $levelString,
            'message' => $message,
            'memory_usage' => memory_get_usage(true),
        ];

        if (count($this->queryLog) > 1000) {
            array_shift($this->queryLog);
        }
    }

    private function createConnection(): PDO
    {
        $config = Config::$db_connection;
        $driver = (string) ($config['driver'] ?? 'mysql');
        $dsn = $this->buildDsn($driver, $config);

        $this->log("[createConnection] Подключение к БД: {$driver}", LogLevel::INFO);

        try {
            $pdo = new PDO(
                $dsn,
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
                $this->connectionOptions($driver)
            );
            $this->log('[createConnection] Соединение успешно установлено', LogLevel::INFO);
            return $pdo;
        } catch (PDOException $e) {
            $this->log('[createConnection] Ошибка подключения: ' . $e->getMessage(), LogLevel::ERROR);
            ServiceLog::emit(
                'database.connection_failed',
                'error',
                'database',
                [
                    'driver' => $driver,
                    'error_type' => $e::class,
                    'error_code' => (string) $e->getCode(),
                ]
            );
            throw $e;
        }
    }

    /** @return array<int,mixed> */
    private function connectionOptions(string $driver): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 30,
            PDO::ATTR_PERSISTENT => false,
        ];

        if ($driver !== 'mysql') {
            return $options;
        }

        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci';
        $options[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
        $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
        return $options;
    }

    private function buildDsn(string $driver, #[\SensitiveParameter] array $config): string
    {
        return match ($driver) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $config['hostname'] ?? 'localhost',
                $config['port'] ?? 3306,
                $config['database'] ?? ''
            ),
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $config['hostname'] ?? 'localhost',
                $config['port'] ?? 5432,
                $config['database'] ?? ''
            ),
            'sqlite' => 'sqlite:' . ($config['database'] ?? ':memory:'),
            default => throw new Exception("Неподдерживаемый драйвер базы данных: {$driver}"),
        };
    }

    private function ensureConnection(): void
    {
        try {
            if ($this->pdo === null) {
                $this->pdo = $this->createConnection();
                return;
            }

            $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (PDOException $e) {
            $this->log('[ensureConnection] Переподключение: ' . $e->getMessage(), LogLevel::WARNING);
            ServiceLog::emit(
                'database.connection_lost',
                'warning',
                'database',
                [
                    'error_type' => $e::class,
                    'error_code' => (string) $e->getCode(),
                ]
            );
            $this->pdo = $this->createConnection();
        }
    }

    public function queueInsert(#[\SensitiveParameter] array $data, string $table): self
    {
        $this->assertIdentifier($table);
        if ($data === []) {
            throw new Exception('Данные INSERT не могут быть пустыми');
        }

        $columns = array_keys($data);
        foreach ($columns as $column) {
            $this->assertIdentifier((string) $column);
        }

        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $query = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->transactionQueue[] = [
            'type' => 'insert',
            'query' => $query,
            'parameters' => $data,
            'table' => $table,
        ];

        return $this;
    }

    public function queueUpdate(#[\SensitiveParameter] array $data, string $table, mixed $id): self
    {
        $this->assertIdentifier($table);
        if ($data === []) {
            throw new Exception('Данные UPDATE не могут быть пустыми');
        }

        $set = [];
        foreach (array_keys($data) as $column) {
            $this->assertIdentifier((string) $column);
            $set[] = $column . ' = :' . $column;
        }

        $data['id'] = $id;
        $this->transactionQueue[] = [
            'type' => 'update',
            'query' => sprintf('UPDATE %s SET %s WHERE id = :id', $table, implode(', ', $set)),
            'parameters' => $data,
            'table' => $table,
        ];

        return $this;
    }

    public function queueDelete(string $table, mixed $id): self
    {
        $this->assertIdentifier($table);
        $this->transactionQueue[] = [
            'type' => 'delete',
            'query' => "DELETE FROM {$table} WHERE id = :id",
            'parameters' => ['id' => $id],
            'table' => $table,
        ];

        return $this;
    }

    /**
     * Атомарно применяет накопленные операции записи.
     *
     * Ошибка одной операции не должна выглядеть как успешный запрос. Старый код
     * иногда игнорировал false, поэтому после отката исходное исключение всегда
     * передаётся вызывающему коду.
     */
    public function commit(): array|false
    {
        if ($this->transactionQueue === []) {
            return [];
        }

        $this->ensureConnection();

        try {
            $this->pdo->beginTransaction();
            $this->inTransaction = true;
            $results = [];

            foreach ($this->transactionQueue as $index => $operation) {
                $stmt = $this->pdo->prepare($operation['query']);
                $started = microtime(true);
                $stmt->execute($operation['parameters']);

                $results[$index] = [
                    'type' => $operation['type'],
                    'affected_rows' => $stmt->rowCount(),
                    'last_insert_id' => $operation['type'] === 'insert'
                        ? (int) $this->pdo->lastInsertId()
                        : null,
                    'exec_time_ms' => round((microtime(true) - $started) * 1000, 2),
                ];
            }

            $this->pdo->commit();
            $this->inTransaction = false;
            $this->transactionQueue = [];
            return $results;
        } catch (Throwable $e) {
            $this->rollbackFailedCommit($e);
            throw $e;
        }
    }

    private function rollbackFailedCommit(Throwable $error): void
    {
        $operationCount = count($this->transactionQueue);
        if ($this->pdo?->inTransaction()) {
            $this->pdo->rollBack();
        }

        $this->inTransaction = false;
        $this->transactionQueue = [];
        $this->log('[commit] Откат: ' . $error->getMessage(), LogLevel::ERROR);
        ServiceLog::emit(
            'database.transaction_failed',
            'error',
            'database',
            [
                'operations' => $operationCount,
                'error_type' => $error::class,
                'error_code' => (string) $error->getCode(),
            ]
        );
    }

    public function rollback(): void
    {
        $this->transactionQueue = [];
        if ($this->pdo?->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->inTransaction = false;
    }

    public function execute(string $query, #[\SensitiveParameter] array $params = []): PDOStatement|int
    {
        $this->ensureConnection();
        $this->queryCount++;

        $queryType = $this->queryType($query);
        if ($this->enableLogging) {
            $this->log(
                "[execute] #{$this->queryCount} {$queryType}: " . $this->maskQuery($query, $params),
                LogLevel::DEBUG
            );
        }

        try {
            $stmt = $this->pdo->prepare($query);
            $started = $this->enableLogging ? microtime(true) : null;
            $stmt->execute($params);
        } catch (Throwable $e) {
            ServiceLog::emit(
                'database.query_failed',
                'error',
                'database',
                [
                    'query_type' => $queryType,
                    'error_type' => $e::class,
                    'error_code' => (string) $e->getCode(),
                ]
            );
            throw $e;
        }

        if ($started !== null) {
            $elapsed = round((microtime(true) - $started) * 1000, 2);
            $this->log("[execute] Завершено за {$elapsed}мс", LogLevel::DEBUG);
        }

        return match ($queryType) {
            'SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'WITH' => $stmt,
            default => $stmt->rowCount(),
        };
    }

    private function queryType(string $query): string
    {
        return DatabaseSqlInspector::queryType($query);
    }

    private function maskQuery(string $query, #[\SensitiveParameter] array $params): string
    {
        return DatabaseSqlInspector::diagnosticQuery($query, $params);
    }

    public function fetchAll(string $query, #[\SensitiveParameter] array $params = []): array
    {
        $stmt = $this->execute($query, $params);
        if (!$stmt instanceof PDOStatement) {
            return [];
        }

        return $stmt->fetchAll();
    }

    public function fetchOne(string $query, #[\SensitiveParameter] array $params = []): ?array
    {
        $stmt = $this->execute($query, $params);
        if (!$stmt instanceof PDOStatement) {
            return null;
        }

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function fetchValue(string $query, #[\SensitiveParameter] array $params = []): mixed
    {
        $stmt = $this->execute($query, $params);
        if (!$stmt instanceof PDOStatement) {
            return null;
        }

        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    public function getPdo(): PDO
    {
        $this->ensureConnection();
        return $this->pdo;
    }

    public function beginTransaction(): bool
    {
        $this->ensureConnection();
        if ($this->pdo->inTransaction()) {
            throw new Exception('Транзакция уже активна');
        }

        $this->inTransaction = $this->pdo->beginTransaction();
        return $this->inTransaction;
    }

    public function endTransaction(bool $commit = true): void
    {
        if (!$this->inTransaction || !$this->pdo?->inTransaction()) {
            $this->inTransaction = false;
            return;
        }

        if ($commit) {
            $this->pdo->commit();
        } else {
            $this->pdo->rollBack();
        }

        $this->inTransaction = false;
    }

    /**
     * Освобождает соединение с БД только когда менеджер действительно простаивает.
     *
     * Это используется длительными HTTP-ожиданиями на виртуальном хостинге:
     * PHP worker может продолжать жить, но не должен всё это время занимать
     * одно из ограниченных MySQL-соединений.
     */
    public function releaseIdleConnection(): void
    {
        if ($this->inTransaction || $this->transactionQueue !== []) {
            return;
        }
        if ($this->pdo?->inTransaction()) {
            return;
        }

        $this->pdo = null;
    }

    public function close(): void
    {
        if ($this->pdo?->inTransaction()) {
            $this->pdo->rollBack();
        }

        $this->pdo = null;
        $this->transactionQueue = [];
        $this->inTransaction = false;
    }

    /** @return array{total_queries:int,log_entries:int} */
    public function getQueryStats(): array
    {
        return [
            'total_queries' => $this->queryCount,
            'log_entries' => count($this->queryLog),
        ];
    }

    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    private function assertIdentifier(string $identifier): void
    {
        DatabaseSqlInspector::assertIdentifier($identifier);
    }

    public function __destruct()
    {
        $this->close();
    }
}
