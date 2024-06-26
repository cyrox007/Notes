<?php
namespace Core;

use PDO;
use Exception;
use Core\Model;

class DatabaseManager {
    private ?PDO $pdo = null;
    private array $transactQueue = [];

    public function __construct() {
        $this->pdo = $this->connectDb();
    }

    public function queueInsert(Model $model): self {
        $insertData = $model->insert();
        $this->transactQueue[] = $insertData;
        return $this;
    }

    public function queueUpdate(Model $model): self {
        $updateData = $model->update();
        $this->transactQueue[] = $updateData;
        return $this;
    }

    public function commit(): bool {
        try {
            // Begin the transaction
            $this->pdo->beginTransaction();

            // Execute all queued transactions
            foreach ($this->transactQueue as $transaction) {
                $stmt = $this->pdo->prepare($transaction['query']);
                foreach ($transaction['parameters'] as $key => $value) {
                    $stmt->bindValue(":$key", $value);
                }
                if (!$stmt->execute()) {
                    throw new Exception('Transaction failed: ' . implode(', ', $stmt->errorInfo()));
                }
            }

            // Commit the transaction if all queries execute successfully
            $this->pdo->commit();

            // Clear transaction queue after successful commit
            $this->transactQueue = [];

            return true;
        } catch (Exception $e) {
            // Roll back any previous changes if an error occurs
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function connectDb(): PDO {
        $config = Config::$db_connection;
        $dsn = "mysql:host={$config['hostname']};port={$config['port']};dbname={$config['database']}";
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        return new PDO($dsn, $config['username'], $config['password'], $options);
    }
}