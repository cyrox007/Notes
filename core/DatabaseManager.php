<?php
namespace Core;

use PDO;
use Exception;
use Core\ORM;

class DatabaseManager {
    private ?PDO $pdo = null;
    private array $transactQueue = [];
    protected string $idPlaceholder = ':id';

    public function setIdPlaceholder($id): void {
        $this->idPlaceholder = $id;
    }

    public function __construct() {
        $this->pdo = $this->connectDb();
    }

    public function queueInsert(ORM $model): self {
        $insertData = $model->insert();
        $this->transactQueue[] = $insertData;
        return $this;
    }

    public function queueUpdate(ORM $model): self {
        $updateData = $model->update();
        $this->transactQueue[] = $updateData;
        return $this;
    }
    
    public function queueDelete(Model $model): self {
        $updateData = $model->delete();
        $this->transactQueue[] = $updateData;
        return $this;
    }

    public function commit(): array|false {
        try {
            // Begin the transaction
            $this->pdo->beginTransaction();

            // Execute all queued transactions
            $insertedIds = [];
            foreach ($this->transactQueue as $transaction) {
                $stmt = $this->pdo->prepare($transaction['query']);
                foreach ($transaction['parameters'] as $key => $value) {
                    if (is_object($value)) continue;
                    $stmt->bindValue(":$key", $value);
                }
                if (!$stmt->execute()) {
                    throw new Exception('Transaction failed: ' . implode(', ', $stmt->errorInfo()));
                }
                $insertedIds[] = $this->pdo->lastInsertId($transaction['parameters']['id']);
            }

            // Commit the transaction if all queries execute successfully
            $this->pdo->commit();

            // Clear transaction queue after successful commit
            $this->transactQueue = [];

            return $insertedIds;
        } catch (Exception $e) {
            // Roll back any previous changes if an error occurs
            $this->pdo->rollBack();
            error_log($e->getMessage());
            return false;
        }
    }

    private function connectDb(): PDO {
        $config = Config::$db_connection;
        $dsn = "mysql:host={$config['hostname']};port={$config['port']};dbname={$config['database']}";
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        return new PDO($dsn, $config['username'], $config['password'], $options);
    }
}