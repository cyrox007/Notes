<?php

declare(strict_types=1);

namespace Core;

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
 * Small PDO manager used by the legacy controllers and the newer service layer.
 *
 * Public API is intentionally kept compatible with the previous implementation.
 */
class DatabaseManager
{
    private static ?self $instance = null;
    private ?PDO $pdo = null;
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
        throw new Exception('Cannot unserialize singleton');
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
        if (self::$instance !== null) {
            self::$instance->close();
            self::$instance = null;
        }
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

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 30,
            PDO::ATTR_PERSISTENT => false,
        ];

        if ($driver === 'mysql') {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci';
            $options[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
            $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
        }

        try {
            $pdo = new PDO(
                $dsn,
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
                $options
            );
            $this->log('[createConnection] Соединение успешно установлено', LogLevel::INFO);
            return $pdo;
        } catch (PDOException $e) {
            $this->log('[createConnection] Ошибка подключения: ' . $e->getMessage(), LogLevel::ERROR);
            throw $e;
        }
    }

    private function buildDsn(string $driver, array $config): string
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
            default => throw new Exception("Unsupported database driver: {$driver}"),
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
            $this->pdo = $this->createConnection();
        }
    }

    public function queueInsert(array $data, string $table): self
    {
        $this->assertIdentifier($table);
        if ($data === []) {
            throw new Exception('INSERT data cannot be empty');
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

    public function queueUpdate(array $data, string $table, mixed $id): self
    {
        $this->assertIdentifier($table);
        if ($data === []) {
            throw new Exception('UPDATE data cannot be empty');
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
     * Commit queued writes atomically.
     *
     * A failed queued write must never be indistinguishable from a successful
     * request. Legacy callers historically ignored a false return value, which
     * allowed controllers to report success after the transaction had rolled
     * back. Keep the method shape compatible, but propagate the original error
     * after rollback so every caller either completes durably or enters its
     * normal exception/error path.
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
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->inTransaction = false;
            $this->transactionQueue = [];
            $this->log('[commit] Откат: ' . $e->getMessage(), LogLevel::ERROR);
            throw $e;
        }
    }

    public function rollback(): void
    {
        $this->transactionQueue = [];
        if ($this->pdo?->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->inTransaction = false;
    }

    public function execute(string $query, array $params = []): PDOStatement|int
    {
        $this->ensureConnection();
        $this->queryCount++;

        $queryType = $this->queryType($query);
        $this->log(
            "[execute] #{$this->queryCount} {$queryType}: " . $this->maskQuery($query, $params),
            LogLevel::DEBUG
        );

        $stmt = $this->pdo->prepare($query);
        $started = microtime(true);
        $stmt->execute($params);
        $elapsed = round((microtime(true) - $started) * 1000, 2);
        $this->log("[execute] Завершено за {$elapsed}мс", LogLevel::DEBUG);

        return match ($queryType) {
            'SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'WITH' => $stmt,
            default => $stmt->rowCount(),
        };
    }

    private function queryType(string $query): string
    {
        if (!preg_match('/^\s*([A-Za-z]+)/', $query, $matches)) {
            return 'UNKNOWN';
        }

        return strtoupper($matches[1]);
    }

    private function maskQuery(string $query, array $params): string
    {
        $masked = $query;
        foreach ($params as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $rendered = '[complex]';
            } elseif ($value === null) {
                $rendered = 'NULL';
            } elseif (is_bool($value)) {
                $rendered = $value ? '1' : '0';
            } else {
                $rendered = (string) $value;
                if (strlen($rendered) > 20) {
                    $rendered = substr($rendered, 0, 20) . '...';
                }
            }

            $placeholder = is_int($key) ? '?' : (string) $key;
            $masked = str_replace($placeholder, $rendered, $masked);
        }

        return $masked;
    }

    public function fetchAll(string $query, array $params = []): array
    {
        $stmt = $this->execute($query, $params);
        if (!$stmt instanceof PDOStatement) {
            return [];
        }

        return $stmt->fetchAll();
    }

    public function fetchOne(string $query, array $params = []): ?array
    {
        $stmt = $this->execute($query, $params);
        if (!$stmt instanceof PDOStatement) {
            return null;
        }

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function fetchValue(string $query, array $params = []): mixed
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
            throw new Exception('Transaction is already active');
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

    public function close(): void
    {
        if ($this->pdo?->inTransaction()) {
            $this->pdo->rollBack();
        }

        $this->pdo = null;
        $this->transactionQueue = [];
        $this->inTransaction = false;
    }

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
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new Exception('Unsafe SQL identifier: ' . $identifier);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
