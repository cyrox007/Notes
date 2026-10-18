<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use Core\MutationCommandStore;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Подзадачи и назначения категорий изменяются вместе с версией родительской задачи.
 * Квитанция команды и все записи фиксируются одной транзакцией.
 */
final class TaskRelationMutationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MutationCommandStore $commands = new MutationCommandStore()
    ) {
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function mutateTask(
        int $userId,
        string $taskUid,
        int $expectedVersion,
        string $commandId,
        string $action,
        array $data
    ): array {
        if ($userId < 1 || preg_match('/^[a-f0-9]{32}$/D', $taskUid) !== 1) {
            throw new InvalidArgumentException('Некорректный пользователь или UID задачи');
        }
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('expected_version должен быть положительным');
        }
        $data = $this->normalizedData($action, $data);
        $hash = $this->commands->requestHash([
            'expected_version' => $expectedVersion,
            'data' => $data,
        ]);
        $this->startTransaction();
        try {
            $receipt = $this->commands->acquire(
                $this->pdo, 'user:' . $userId, $commandId, 'tasks',
                $action, 'task', $taskUid, $hash
            );
            $task = $this->ownedTask($userId, $taskUid);
            if ($task === null) {
                throw new DomainException('Задача не найдена или недоступна', 404);
            }
            if (!$receipt['owner']) {
                $result = $this->commands->storedResponse($receipt['record']);
                $this->pdo->commit();
                return $result;
            }

            $currentVersion = (int) $task['version'];
            if ($currentVersion !== $expectedVersion) {
                $result = [
                    'success' => false,
                    'error' => 'version_conflict',
                    'message' => 'Задача уже изменена в другой вкладке',
                    'task_uid' => $taskUid,
                    'expected_version' => $expectedVersion,
                    'current_version' => $currentVersion,
                ];
                $this->finish($receipt, $result, $currentVersion, MutationCommandStore::STATUS_CONFLICT);
                $this->pdo->commit();
                return $result;
            }

            $details = $this->applyTaskAction($userId, (int) $task['id'], $action, $data);
            $version = $currentVersion;
            if ($details['changed']) {
                $version = $this->increaseVersion((int) $task['id'], $expectedVersion);
            }
            unset($details['changed']);
            $result = [
                'success' => true,
                'task_uid' => $taskUid,
                'version' => $version,
                'replayed' => false,
                ...$details,
            ];
            $this->finish($receipt, $result, $version, MutationCommandStore::STATUS_APPLIED);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function createCategory(
        int $userId,
        string $commandId,
        string $name,
        string $color,
        string $icon
    ): array {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $color = strtolower(trim($color));
        $icon = strtolower(trim($icon));
        if ($userId < 1 || $name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Название категории должно содержать от 1 до 120 символов');
        }
        if (preg_match('/^#[0-9a-f]{6}$/D', $color) !== 1) {
            throw new InvalidArgumentException('Некорректный цвет категории');
        }
        if (preg_match('/^fa-[a-z0-9-]{1,48}$/D', $icon) !== 1) {
            throw new InvalidArgumentException('Некорректная иконка категории');
        }
        $hash = $this->commands->requestHash([
            'name' => $name, 'color' => $color, 'icon' => $icon,
        ]);
        $this->startTransaction();
        try {
            $receipt = $this->commands->acquire(
                $this->pdo, 'user:' . $userId, $commandId, 'tasks',
                'task.category.create', 'task_category', 'user:' . $userId, $hash
            );
            $active = $this->pdo->prepare(
                "SELECT id FROM users WHERE id=:user_id
                 AND is_active=1 AND account_status='active' LIMIT 1 FOR UPDATE"
            );
            $active->execute([':user_id' => $userId]);
            if ($active->fetchColumn() === false) {
                throw new DomainException('Пользователь недоступен', 403);
            }
            if (!$receipt['owner']) {
                $result = $this->commands->storedResponse($receipt['record']);
                $this->pdo->commit();
                return $result;
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO task_categories
                  (user_id,name,color,icon,sort_order,is_deleted,created_at,updated_at)
                 VALUES (:user_id,:name,:color,:icon,0,0,NOW(),NOW())'
            );
            $insert->execute([
                ':user_id' => $userId,
                ':name' => $name,
                ':color' => $color,
                ':icon' => $icon,
            ]);
            $categoryId = (int) $this->pdo->lastInsertId();
            $result = [
                'success' => true,
                'category' => [
                    'id' => $categoryId,
                    'name' => $name,
                    'color' => $color,
                    'icon' => $icon,
                ],
                'replayed' => false,
            ];
            $this->finish($receipt, $result, null, MutationCommandStore::STATUS_APPLIED);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function normalizedData(string $action, array $data): array
    {
        return match ($action) {
            'task.subtask.create' => ['title' => $this->subtaskTitle($data['title'] ?? null)],
            'task.subtask.complete' => [
                'subtask_id' => $this->positiveId($data['subtask_id'] ?? null),
                'is_completed' => $this->completedValue($data['is_completed'] ?? null),
            ],
            'task.subtask.delete' => ['subtask_id' => $this->positiveId($data['subtask_id'] ?? null)],
            'task.category.attach', 'task.category.detach' => [
                'category_id' => $this->positiveId($data['category_id'] ?? null),
            ],
            default => throw new InvalidArgumentException('Неизвестное действие с подзадачей или категорией'),
        };
    }

    private function subtaskTitle(mixed $title): string
    {
        if (!is_string($title)) {
            throw new InvalidArgumentException('Некорректное название подзадачи');
        }
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Название подзадачи должно содержать от 1 до 255 символов');
        }
        return $title;
    }

    private function positiveId(mixed $value): int
    {
        if (!is_scalar($value) || preg_match('/^[1-9][0-9]*$/D', (string) $value) !== 1) {
            throw new InvalidArgumentException('Некорректный идентификатор объекта');
        }
        return (int) $value;
    }

    private function completedValue(mixed $value): int
    {
        if (!is_scalar($value) || !in_array((string) $value, ['0', '1'], true)) {
            throw new InvalidArgumentException('Некорректное состояние подзадачи');
        }
        return (int) $value;
    }

    /** @return array<string,mixed>|null */
    private function ownedTask(int $userId, string $uid): ?array
    {
        $select = $this->pdo->prepare(
            "SELECT t.id,t.uid,t.version FROM tasks t
             INNER JOIN users u ON u.id=t.user_id AND u.is_active=1
                 AND u.account_status='active'
             WHERE t.uid=:uid AND t.user_id=:user_id AND t.is_deleted=0
             LIMIT 1 FOR UPDATE"
        );
        $select->execute([':uid' => $uid, ':user_id' => $userId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function applyTaskAction(int $userId, int $taskId, string $action, array $data): array
    {
        return match ($action) {
            'task.subtask.create' => $this->addSubtask($taskId, $data['title']),
            'task.subtask.complete' => $this->completeSubtask(
                $taskId, $data['subtask_id'], $data['is_completed']
            ),
            'task.subtask.delete' => $this->removeSubtask($taskId, $data['subtask_id']),
            'task.category.attach' => $this->changeCategory($userId, $taskId, $data['category_id'], true),
            'task.category.detach' => $this->changeCategory($userId, $taskId, $data['category_id'], false),
            default => throw new InvalidArgumentException('Неизвестное действие'),
        };
    }

    /** @return array<string,mixed> */
    private function addSubtask(int $taskId, string $title): array
    {
        $position = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), -1)+1 FROM subtasks WHERE task_id=:task_id'
        );
        $position->execute([':task_id' => $taskId]);
        $sortOrder = (int) $position->fetchColumn();
        $insert = $this->pdo->prepare(
            'INSERT INTO subtasks
             (task_id,title,is_completed,completed_at,sort_order,created_at,updated_at)
             VALUES (:task_id,:title,0,NULL,:sort_order,NOW(),NOW())'
        );
        $insert->execute([
            ':task_id' => $taskId,
            ':title' => $title,
            ':sort_order' => $sortOrder,
        ]);
        return [
            'changed' => true,
            'subtask' => [
                'id' => (int) $this->pdo->lastInsertId(),
                'title' => $title,
                'is_completed' => 0,
                'sort_order' => $sortOrder,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function completeSubtask(int $taskId, int $subtaskId, int $completed): array
    {
        $row = $this->subtask($taskId, $subtaskId);
        if ((int) $row['is_completed'] === $completed) {
            return ['changed' => false, 'is_completed' => $completed, 'subtask_id' => $subtaskId];
        }
        $statement = $this->pdo->prepare(
            'UPDATE subtasks SET is_completed=:completed,completed_at=:completed_at,
                updated_at=NOW() WHERE id=:id AND task_id=:task_id'
        );
        $statement->execute([
            ':completed' => $completed,
            ':completed_at' => $completed === 1 ? date('Y-m-d H:i:s') : null,
            ':id' => $subtaskId,
            ':task_id' => $taskId,
        ]);
        return ['changed' => true, 'is_completed' => $completed, 'subtask_id' => $subtaskId];
    }

    /** @return array<string,mixed> */
    private function removeSubtask(int $taskId, int $subtaskId): array
    {
        $this->subtask($taskId, $subtaskId);
        $stmt = $this->pdo->prepare('DELETE FROM subtasks WHERE id=:id AND task_id=:task_id');
        $stmt->execute([':id' => $subtaskId, ':task_id' => $taskId]);
        return ['changed' => true, 'subtask_id' => $subtaskId];
    }

    /** @return array<string,mixed> */
    private function subtask(int $taskId, int $subtaskId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id,is_completed FROM subtasks WHERE id=:id AND task_id=:task_id LIMIT 1'
        );
        $stmt->execute([':id' => $subtaskId, ':task_id' => $taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Подзадача не найдена или недоступна', 404);
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function changeCategory(int $userId, int $taskId, int $categoryId, bool $attach): array
    {
        $category = $this->pdo->prepare(
            'SELECT id FROM task_categories WHERE id=:id AND is_deleted=0
             AND (user_id=:user_id OR user_id IS NULL) LIMIT 1'
        );
        $category->execute([':id' => $categoryId, ':user_id' => $userId]);
        if ($category->fetchColumn() === false) {
            throw new DomainException('Категория не найдена или недоступна', 404);
        }
        $exists = $this->pdo->prepare(
            'SELECT id FROM task_category_relations WHERE task_id=:task_id AND category_id=:category_id'
        );
        $exists->execute([':task_id' => $taskId, ':category_id' => $categoryId]);
        $present = $exists->fetchColumn() !== false;
        if ($present === $attach) {
            return ['changed' => false, 'category_id' => $categoryId, 'attached' => $attach];
        }
        if ($attach) {
            $query = 'INSERT INTO task_category_relations (task_id,category_id,created_at)
                      VALUES (:task_id,:category_id,NOW())';
        } else {
            $query = 'DELETE FROM task_category_relations
                      WHERE task_id=:task_id AND category_id=:category_id';
        }
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([':task_id' => $taskId, ':category_id' => $categoryId]);
        return ['changed' => true, 'category_id' => $categoryId, 'attached' => $attach];
    }

    private function increaseVersion(int $taskId, int $expectedVersion): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tasks SET version=version+1,updated_at=NOW()
             WHERE id=:id AND version=:expected AND is_deleted=0'
        );
        $stmt->execute([':id' => $taskId, ':expected' => $expectedVersion]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Изменение версии задачи не было зафиксировано');
        }
        return $expectedVersion + 1;
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $response */
    private function finish(array $receipt, array $response, ?int $version, string $status): void
    {
        $this->commands->complete(
            $this->pdo, (int) $receipt['record']['id'], $receipt['token'],
            $status, $response, $version
        );
    }

    private function startTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Изменение связанной сущности требует отдельную транзакцию');
        }
        $this->pdo->beginTransaction();
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
