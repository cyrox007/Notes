<?php

declare(strict_types=1);

namespace Modules\Tasks;

use Core\DatabaseManager;
use Core\MutationCommandStore;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Назначенный исполнитель подтверждает получение задачи отдельной версионной командой.
 * Права и назначение перепроверяются внутри транзакции до возврата результата повтора.
 */
final class TaskBoardAcceptanceService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly MutationCommandStore $commands = new MutationCommandStore()
    ) {
    }

    /** @return array<string,mixed> */
    public function accept(int $actor, string $uid, int $expectedVersion, string $commandId): array
    {
        if ($actor < 1 || preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1
            || $expectedVersion < 1) {
            throw new InvalidArgumentException('Некорректный исполнитель, задача или версия');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/D', $commandId) !== 1) {
            throw new InvalidArgumentException('Некорректный идентификатор команды');
        }
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Подтверждение требует отдельной транзакции');
        }

        $hash = $this->commands->requestHash(['expected_version' => $expectedVersion]);
        $this->db->beginTransaction();
        try {
            $task = $this->db->prepare(
                'SELECT i.id,i.uid,i.board_id,i.status,i.version,i.is_deleted,i.archived_at,
                        i.accepted_by_user_id,i.accepted_at,
                        b.uid AS board_uid,b.is_archived AS board_archived,b.owner_user_id,b.audience,
                        bm.role AS member_role
                 FROM task_board_items i
                 INNER JOIN task_boards b ON b.id=i.board_id
                 INNER JOIN users u ON u.id=:actor AND u.is_active=1 AND u.account_status=:active
                 LEFT JOIN task_board_members bm ON bm.board_id=b.id AND bm.user_id=:member
                 WHERE i.uid=:uid LIMIT 1 FOR UPDATE'
            );
            $task->execute([
                ':actor' => $actor, ':active' => 'active',
                ':member' => $actor, ':uid' => $uid,
            ]);
            $row = $task->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (int) $row['is_deleted'] !== 0
                || (int) $row['board_archived'] !== 0 || $row['archived_at'] !== null) {
                throw new DomainException('Задача недоступна для принятия', 404);
            }

            $role = (string) ($row['member_role'] ?? '');
            $canRead = (int) $row['owner_user_id'] === $actor
                || in_array($role, ['manager', 'member', 'viewer'], true)
                || (string) $row['audience'] === 'all_active';
            if (!$canRead) {
                throw new DomainException('Задача недоступна', 404);
            }
            if ($role === 'viewer') {
                throw new DomainException('Наблюдатель не может принимать задачи', 403);
            }

            $assigned = $this->db->fetchValue(
                'SELECT 1 FROM task_board_assignees WHERE task_id=:task AND user_id=:actor LIMIT 1',
                [':task' => (int) $row['id'], ':actor' => $actor]
            );
            if ($assigned === null) {
                throw new DomainException('Принять задачу может только назначенный исполнитель', 403);
            }

            $receipt = $this->commands->acquire(
                $this->db, 'user:' . $actor, $commandId, 'tasks',
                'task.api.board.accept', 'task_board_item', $uid, $hash
            );
            if (!$receipt['owner']) {
                $response = $this->commands->storedResponse($receipt['record']);
                $this->db->endTransaction();
                return $response;
            }

            $version = (int) $row['version'];
            if ($version !== $expectedVersion) {
                $response = [
                    'success' => false, 'error' => 'version_conflict',
                    'task_uid' => $uid, 'expected_version' => $expectedVersion,
                    'current_version' => $version,
                ];
                $this->complete($receipt, $response, $version, MutationCommandStore::STATUS_CONFLICT);
                $this->db->endTransaction();
                return $response;
            }
            if (!in_array((string) $row['status'], ['pending', 'studying'], true)
                || $row['accepted_at'] !== null) {
                throw new DomainException('Задача уже взята в работу либо недоступна', 409);
            }

            $now = gmdate('Y-m-d H:i:s');
            $changed = $this->db->execute(
                "UPDATE task_board_items
                 SET status='in_progress',accepted_by_user_id=:actor,accepted_at=:accepted,
                     version=version+1,updated_at=:updated
                 WHERE id=:id AND version=:version AND accepted_at IS NULL
                   AND status IN ('pending','studying')",
                [
                    ':actor' => $actor, ':accepted' => $now, ':updated' => $now,
                    ':id' => (int) $row['id'], ':version' => $version,
                ]
            );
            if ($changed !== 1) {
                throw new RuntimeException('Не удалось атомарно подтвердить принятие задачи');
            }
            $this->db->execute(
                'UPDATE task_boards SET updated_at=:now WHERE id=:id',
                [':now' => $now, ':id' => (int) $row['board_id']]
            );
            $response = [
                'success' => true, 'task_uid' => $uid,
                'board_uid' => (string) $row['board_uid'],
                'status' => 'in_progress', 'version' => $version + 1,
                'accepted_by_user_id' => $actor, 'accepted_at' => $now,
                'replayed' => false,
            ];
            $this->complete($receipt, $response, $version + 1, MutationCommandStore::STATUS_APPLIED);
            $this->db->endTransaction();
            return $response;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->endTransaction(false);
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $response */
    private function complete(array $receipt, array $response, int $version, string $status): void
    {
        $this->commands->complete(
            $this->db, (int) $receipt['record']['id'], $receipt['token'],
            $status, $response, $version
        );
    }
}
