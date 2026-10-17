<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use Core\MutationCommandStore;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Атомарно изменяет личную задачу по ожидаемой версии.
 *
 * Один command_id можно безопасно повторять после потерянного ответа. Повтор с
 * другим payload отклоняется. Устаревшая expected_version возвращает явный
 * конфликт и никогда не перезаписывает более новую задачу.
 */
final class TaskVersionMutationService
{
    /** @var list<string> */
    private const array MUTABLE_COLUMNS = [
        'title',
        'description',
        'status',
        'priority',
        'due_date',
        'completed_at',
        'archived_at',
        'is_deleted',
        'deleted_at',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly MutationCommandStore $commands = new MutationCommandStore()
    ) {
    }

    /**
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    public function mutateOwned(
        int $userId,
        string $taskUid,
        int $expectedVersion,
        string $commandId,
        string $action,
        array $changes
    ): array {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Некорректный пользователь изменяющей команды');
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $taskUid) !== 1) {
            throw new InvalidArgumentException('Некорректный UID задачи');
        }
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('expected_version должен быть положительным числом');
        }
        if ($changes === []) {
            throw new InvalidArgumentException('Изменяющая команда не содержит изменений');
        }

        $normalized = $this->normalizeChanges($changes);
        $requestHash = $this->commands->requestHash([
            'expected_version' => $expectedVersion,
            'changes' => $normalized,
        ]);

        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('TaskVersionMutationService требует самостоятельную транзакцию');
        }

        $this->pdo->beginTransaction();
        try {
            $receipt = $this->commands->acquire(
                $this->pdo,
                'user:' . $userId,
                $commandId,
                'tasks',
                $action,
                'task',
                $taskUid,
                $requestHash
            );

            if (!$receipt['owner']) {
                $response = $this->commands->storedResponse($receipt['record']);
                $this->pdo->commit();
                return $response;
            }

            $task = $this->lockTask($userId, $taskUid);
            if ($task === null) {
                throw new RuntimeException('Задача не найдена или недоступна');
            }

            $currentVersion = (int) ($task['version'] ?? 1);
            if ($currentVersion !== $expectedVersion) {
                $response = [
                    'success' => false,
                    'error' => 'version_conflict',
                    'message' => 'Задача уже была изменена в другой вкладке или другим запросом',
                    'task_uid' => $taskUid,
                    'expected_version' => $expectedVersion,
                    'current_version' => $currentVersion,
                    'current' => $this->publicTask($task),
                ];
                $this->commands->complete(
                    $this->pdo,
                    (int) $receipt['record']['id'],
                    $receipt['token'],
                    MutationCommandStore::STATUS_CONFLICT,
                    $response,
                    $currentVersion
                );
                $this->pdo->commit();
                return $response;
            }

            $now = date('Y-m-d H:i:s');
            $set = [];
            $params = [
                ':id' => (int) $task['id'],
                ':user_id' => $userId,
                ':expected_version' => $expectedVersion,
                ':updated_at' => $now,
            ];
            foreach ($normalized as $column => $value) {
                $placeholder = ':change_' . $column;
                $set[] = '`' . $column . '` = ' . $placeholder;
                $params[$placeholder] = $value;
            }
            $set[] = '`version` = `version` + 1';
            $set[] = '`updated_at` = :updated_at';

            $statement = $this->pdo->prepare(
                'UPDATE tasks SET ' . implode(', ', $set)
                . ' WHERE id=:id AND user_id=:user_id AND version=:expected_version'
            );
            $statement->execute($params);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Версионное изменение задачи не было применено атомарно');
            }

            $current = $this->lockTask($userId, $taskUid);
            if ($current === null) {
                throw new RuntimeException('Задача исчезла после изменяющей команды');
            }
            $resultVersion = (int) $current['version'];
            $response = [
                'success' => true,
                'task_uid' => $taskUid,
                'version' => $resultVersion,
                'current' => $this->publicTask($current),
                'replayed' => false,
            ];

            $this->commands->complete(
                $this->pdo,
                (int) $receipt['record']['id'],
                $receipt['token'],
                MutationCommandStore::STATUS_APPLIED,
                $response,
                $resultVersion
            );
            $this->pdo->commit();
            return $response;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private function normalizeChanges(array $changes): array
    {
        $normalized = [];
        foreach ($changes as $column => $value) {
            if (!is_string($column) || !in_array($column, self::MUTABLE_COLUMNS, true)) {
                throw new InvalidArgumentException('Недопустимое поле изменяющей команды Tasks');
            }
            $normalized[$column] = $value;
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    /** @return array<string,mixed>|null */
    private function lockTask(int $userId, string $taskUid): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id,uid,user_id,title,description,status,priority,due_date,completed_at,
                    archived_at,is_deleted,deleted_at,version,created_at,updated_at
             FROM tasks
             WHERE uid=:uid AND user_id=:user_id
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute([':uid' => $taskUid, ':user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function publicTask(array $task): array
    {
        return [
            'uid' => (string) $task['uid'],
            'title' => (string) $task['title'],
            'description' => $task['description'],
            'status' => (string) $task['status'],
            'priority' => (string) $task['priority'],
            'due_date' => $task['due_date'],
            'completed_at' => $task['completed_at'],
            'archived_at' => $task['archived_at'],
            'is_deleted' => (int) $task['is_deleted'],
            'deleted_at' => $task['deleted_at'],
            'version' => (int) $task['version'],
            'updated_at' => (string) $task['updated_at'],
        ];
    }
}
