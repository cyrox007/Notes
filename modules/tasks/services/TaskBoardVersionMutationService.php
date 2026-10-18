<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Services\RolePolicyService;
use Core\DatabaseManager;
use Core\MutationCommandStore;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Атомарные изменения задач общих досок.
 * Проверка роли, версия, исполнители и квитанция команды живут в одной транзакции.
 */
final class TaskBoardVersionMutationService
{
    private const array FIELDS = [
        'title', 'description', 'status', 'priority', 'due_date',
        'archived', 'assignee_ids', 'delete',
    ];
    private const array STATUSES = ['pending', 'studying', 'in_progress', 'review', 'completed'];
    private const array PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly MutationCommandStore $commands = new MutationCommandStore(),
        private readonly ?\Closure $canAssign = null
    ) {
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function mutate(
        int $actorId,
        string $uid,
        int $expectedVersion,
        string $commandId,
        string $action,
        array $changes
    ): array {
        if ($actorId < 1 || preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
            throw new InvalidArgumentException('Некорректная задача или пользователь');
        }
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('Требуется положительная expected_version');
        }
        $changes = $this->normalize($changes);
        $hash = $this->commands->requestHash([
            'expected_version' => $expectedVersion,
            'changes' => $changes,
        ]);
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Версионная команда требует отдельную транзакцию');
        }

        $this->pdo->beginTransaction();
        try {
            // Проверяем актуальные права ДО возврата результата повтора.
            // FOR UPDATE сериализует изменения задачи и состава участников доски.
            $task = $this->lockedTask($actorId, $uid);
            $this->assertCanWrite($actorId, $task);

            $receipt = $this->commands->acquire(
                $this->pdo, 'user:' . $actorId, $commandId,
                'tasks', $action, 'task_board_item', $uid, $hash
            );
            if (!$receipt['owner']) {
                $result = $this->commands->storedResponse($receipt['record']);
                $this->pdo->commit();
                return $result;
            }
            if ((int) $task['is_deleted'] !== 0) {
                throw new DomainException('Задача общей доски удалена', 404);
            }

            $version = (int) $task['version'];
            if ($expectedVersion !== $version) {
                $result = [
                    'success' => false,
                    'error' => 'version_conflict',
                    'message' => 'Задача общей доски уже изменена другим участником',
                    'task_uid' => $uid,
                    'board_uid' => (string) $task['board_uid'],
                    'expected_version' => $expectedVersion,
                    'current_version' => $version,
                    'current' => $this->publicTask($task),
                ];
                $this->complete($receipt, $result, $version, MutationCommandStore::STATUS_CONFLICT);
                $this->pdo->commit();
                return $result;
            }

            if (!empty($task['archived_at']) && isset($changes['status'])) {
                throw new InvalidArgumentException('Сначала восстановите задачу из архива');
            }

            $assignees = $changes['assignee_ids'] ?? null;
            if ($assignees !== null) {
                $this->assertAssignable($actorId, $task, $assignees);
            }
            $set = $this->setColumns($task, $changes);
            $params = [':id' => (int) $task['id'], ':expected' => $expectedVersion];
            $parts = [];
            foreach ($set as $column => $value) {
                $key = ':v_' . $column;
                $parts[] = $column . ' = ' . $key;
                $params[$key] = $value;
            }
            $parts[] = 'version = version + 1';
            $parts[] = 'updated_at = CURRENT_TIMESTAMP';
            $update = $this->pdo->prepare(
                'UPDATE task_board_items SET ' . implode(', ', $parts)
                . ' WHERE id=:id AND version=:expected AND is_deleted=0'
            );
            $update->execute($params);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Не удалось атомарно изменить версию общей задачи');
            }

            if ($assignees !== null) {
                $this->saveAssignees((int) $task['id'], $assignees);
            }
            $touch = $this->pdo->prepare('UPDATE task_boards SET updated_at=CURRENT_TIMESTAMP WHERE id=:id');
            $touch->execute([':id' => (int) $task['board_id']]);

            $current = $this->lockedTask($actorId, $uid);
            $result = [
                'success' => true,
                'task_uid' => $uid,
                'board_uid' => (string) $task['board_uid'],
                'status' => (string) $current['status'],
                'version' => (int) $current['version'],
                'current' => $this->publicTask($current),
                'replayed' => false,
            ];
            $this->complete($receipt, $result, (int) $current['version'], MutationCommandStore::STATUS_APPLIED);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function lockedTask(int $actorId, string $uid): array
    {
        $sql = "SELECT i.*, b.uid AS board_uid, b.owner_user_id, b.audience,
                       b.is_archived AS board_archived, bm.role AS member_role
                FROM task_board_items i
                INNER JOIN task_boards b ON b.id=i.board_id
                INNER JOIN users actor ON actor.id=:actor AND actor.is_active=1
                    AND actor.account_status='active'
                LEFT JOIN task_board_members bm ON bm.board_id=b.id AND bm.user_id=:member
                WHERE i.uid=:uid LIMIT 1 FOR UPDATE";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':actor' => $actorId, ':member' => $actorId, ':uid' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Задача общей доски не найдена или недоступна', 404);
        }
        return $row;
    }

    /** @param array<string,mixed> $task */
    private function assertCanWrite(int $actorId, array $task): void
    {
        if ((int) $task['board_archived'] !== 0) {
            throw new DomainException('Общая доска находится в архиве', 403);
        }
        if ((int) $task['owner_user_id'] === $actorId) {
            return;
        }
        $role = (string) ($task['member_role'] ?? '');
        if ($role === 'viewer') {
            throw new DomainException('Доска доступна только для просмотра', 403);
        }
        if (in_array($role, ['manager', 'member'], true)
            || ($role === '' && (string) $task['audience'] === 'all_active')) {
            return;
        }
        throw new DomainException('Задача общей доски не найдена или недоступна', 404);
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private function normalize(array $changes): array
    {
        if ($changes === []) {
            throw new InvalidArgumentException('Изменения задачи не переданы');
        }
        $result = [];
        foreach ($changes as $field => $value) {
            if (!is_string($field) || !in_array($field, self::FIELDS, true)) {
                throw new InvalidArgumentException('Недопустимое поле общей задачи');
            }
            $result[$field] = match ($field) {
                'title' => $this->text($value, 255, true),
                'description' => $this->text($value, 10000, false),
                'status' => $this->choice($value, self::STATUSES, 'статус'),
                'priority' => $this->choice($value, self::PRIORITIES, 'приоритет'),
                'due_date' => $this->dueDate($value),
                'archived' => $this->flag($value),
                'delete' => $this->flag($value),
                'assignee_ids' => $this->ids($value),
            };
        }
        if (isset($result['delete']) && $result['delete'] !== '1') {
            throw new InvalidArgumentException('Некорректное удаление задачи');
        }
        if (isset($result['delete']) && count($result) !== 1) {
            throw new InvalidArgumentException('Удаление не объединяется с редактированием');
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function text(mixed $value, int $max, bool $required): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Ожидался текст задачи');
        }
        $value = trim($value);
        if (($required && $value === '') || mb_strlen($value) > $max) {
            throw new InvalidArgumentException('Некорректная длина текста задачи');
        }
        return $value;
    }

    /** @param list<string> $allowed */
    private function choice(mixed $value, array $allowed, string $name): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Некорректный ' . $name . ' задачи');
        }
        return $value;
    }

    private function flag(mixed $value): string
    {
        if (!in_array((string) $value, ['0', '1'], true)) {
            throw new InvalidArgumentException('Некорректный флаг изменяющей команды');
        }
        return (string) $value;
    }

    private function dueDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Некорректный срок задачи');
        }
        foreach (['Y-m-d\\TH:i', 'Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof DateTimeImmutable && $date->format($format) === $value) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        throw new InvalidArgumentException('Некорректная дата задачи');
    }

    /** @return list<int> */
    private function ids(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Некорректный состав исполнителей');
        }
        $ids = [];
        foreach ($value as $entry) {
            if (!is_scalar($entry) || preg_match('/^[1-9][0-9]*$/D', (string) $entry) !== 1) {
                throw new InvalidArgumentException('Некорректный идентификатор исполнителя');
            }
            $ids[(int) $entry] = true;
        }
        $result = array_keys($ids);
        sort($result, SORT_NUMERIC);
        return $result;
    }

    /** @param array<string,mixed> $task @param array<string,mixed> $changes @return array<string,mixed> */
    private function setColumns(array $task, array $changes): array
    {
        $set = [];
        foreach (['title', 'description', 'status', 'priority', 'due_date'] as $field) {
            if (array_key_exists($field, $changes)) {
                $set[$field] = $changes[$field];
            }
        }
        if (isset($changes['status'])) {
            $set['completed_at'] = $changes['status'] === 'completed'
                ? ($task['completed_at'] ?? date('Y-m-d H:i:s')) : null;
        }
        if (isset($changes['archived'])) {
            $set['archived_at'] = $changes['archived'] === '1'
                ? ($task['archived_at'] ?? date('Y-m-d H:i:s')) : null;
        }
        if (isset($changes['delete'])) {
            $set['is_deleted'] = 1;
            $set['deleted_at'] = date('Y-m-d H:i:s');
        }
        return $set;
    }

    /** @param array<string,mixed> $task @param list<int> $ids */
    private function assertAssignable(int $actorId, array $task, array $ids): void
    {
        $allowed = $this->canAssign !== null
            ? (bool) ($this->canAssign)($actorId)
            : (bool) (new RolePolicyService(DatabaseManager::getInstance()))
                ->effectiveValue($actorId, 'tasks', 'can_assign_tasks');
        if (!$allowed) {
            throw new DomainException('Назначение задач отключено для вашей роли', 403);
        }
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ((string) $task['audience'] === 'all_active') {
            $sql = "SELECT id FROM users WHERE id IN ($placeholders)
                    AND is_active=1 AND account_status='active'";
            $params = $ids;
        } else {
            $sql = "SELECT u.id FROM users u
                    WHERE u.id IN ($placeholders) AND u.is_active=1 AND u.account_status='active'
                      AND (u.id=? OR EXISTS (
                        SELECT 1 FROM task_board_members m
                        WHERE m.board_id=? AND m.user_id=u.id
                      ))";
            $params = [...$ids, (int) $task['owner_user_id'], (int) $task['board_id']];
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        sort($found, SORT_NUMERIC);
        if ($found !== $ids) {
            throw new InvalidArgumentException('Исполнители должны быть активными участниками доски');
        }
    }

    /** @param list<int> $ids */
    private function saveAssignees(int $taskId, array $ids): void
    {
        $delete = $this->pdo->prepare('DELETE FROM task_board_assignees WHERE task_id=:id');
        $delete->execute([':id' => $taskId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO task_board_assignees (task_id,user_id,assigned_at) VALUES (:task,:user,CURRENT_TIMESTAMP)'
        );
        foreach ($ids as $id) {
            $insert->execute([':task' => $taskId, ':user' => $id]);
        }
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function publicTask(array $task): array
    {
        $select = $this->pdo->prepare(
            'SELECT user_id FROM task_board_assignees WHERE task_id=:id ORDER BY user_id'
        );
        $select->execute([':id' => (int) $task['id']]);
        return [
            'uid' => (string) $task['uid'],
            'board_uid' => (string) $task['board_uid'],
            'title' => (string) $task['title'],
            'description' => (string) $task['description'],
            'status' => (string) $task['status'],
            'priority' => (string) $task['priority'],
            'due_date' => $task['due_date'],
            'completed_at' => $task['completed_at'],
            'archived_at' => $task['archived_at'],
            'is_deleted' => (int) $task['is_deleted'],
            'version' => (int) $task['version'],
            'assignee_ids' => array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN)),
        ];
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $response */
    private function complete(array $receipt, array $response, int $version, string $status): void
    {
        $this->commands->complete(
            $this->pdo,
            (int) $receipt['record']['id'],
            $receipt['token'],
            $status,
            $response,
            $version
        );
    }
}
