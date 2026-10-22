<?php

declare(strict_types=1);

namespace Modules\Tasks;

use App\Services\RolePolicyService;
use App\Services\TaskBoardService;
use Core\DatabaseManager;
use Core\MutationCommandStore;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Единичное изменение роли участника — атомарная команда с проверкой прав.
 * Владелец управляет всеми ролями; руководитель — только участниками и наблюдателями.
 */
final class TaskBoardMemberCommandService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly MutationCommandStore $commands = new MutationCommandStore(),
        private readonly ?\Closure $memberLimit = null
    ) {
    }

    /** @return array<string,mixed> */
    public function change(
        int $actor,
        string $boardUid,
        int $target,
        string $commandId,
        ?string $expectedRole,
        ?string $newRole
    ): array {
        if ($actor < 1 || $target < 1 || preg_match('/^[a-f0-9]{32}$/D', $boardUid) !== 1) {
            throw new InvalidArgumentException('Некорректный участник или доска');
        }
        if (!in_array($expectedRole, [null, 'manager', 'member', 'viewer'], true)
            || !in_array($newRole, [null, 'manager', 'member', 'viewer'], true)
            || ($expectedRole === null && $newRole === null)) {
            throw new InvalidArgumentException('Недопустимое изменение роли');
        }
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Команда требует самостоятельной транзакции');
        }

        $hash = $this->commands->requestHash([
            'target' => $target, 'expected_role' => $expectedRole, 'role' => $newRole,
        ]);
        $this->db->beginTransaction();
        try {
            // Сначала блокируем доску; проверка роли и обновление всегда под этим замком.
            $lock = $this->db->prepare(
                'SELECT id FROM task_boards WHERE uid=:uid AND is_archived=0 FOR UPDATE'
            );
            $lock->execute([':uid' => $boardUid]);
            $boardId = (int) ($lock->fetchColumn() ?: 0);
            if ($boardId <= 0) {
                throw new DomainException('Доска недоступна', 404);
            }
            $board = (new TaskBoardService($this->db))->boardForUser($actor, $boardUid);
            if ((string) $board['audience'] !== 'members') {
                throw new DomainException('Состав общедоступной доски определяется автоматически', 403);
            }
            $actorRole = (string) $board['access_role'];
            if (!in_array($actorRole, ['owner', 'manager'], true)) {
                throw new DomainException('Управление участниками недоступно', 403);
            }
            if ($target === (int) $board['owner_user_id']) {
                throw new DomainException('Нельзя изменить роль владельца', 403);
            }
            if ($actorRole !== 'owner' && ($expectedRole === 'manager' || $newRole === 'manager')) {
                throw new DomainException('Руководитель не может управлять другими руководителями', 403);
            }
            $member = $this->db->prepare(
                'SELECT role FROM task_board_members WHERE board_id=:board AND user_id=:target FOR UPDATE'
            );
            $member->execute([':board' => $boardId, ':target' => $target]);
            $actualRole = $member->fetchColumn();
            $actualRole = $actualRole === false ? null : (string) $actualRole;
            if ($actorRole !== 'owner' && $actualRole === 'manager') {
                throw new DomainException('Руководитель не может изменить другого руководителя', 403);
            }

            $receipt = $this->commands->acquire(
                $this->db, 'user:' . $actor, $commandId, 'tasks',
                'task.api.board.member', 'task_board', $boardUid, $hash
            );
            if (!$receipt['owner']) {
                $answer = $this->commands->storedResponse($receipt['record']);
                $this->db->endTransaction();
                return $answer;
            }

            if ($actualRole !== $expectedRole) {
                $response = [
                    'success' => false, 'error' => 'role_conflict',
                    'board_uid' => $boardUid, 'user_id' => $target,
                    'expected_role' => $expectedRole, 'current_role' => $actualRole,
                ];
                $this->commands->complete(
                    $this->db, (int) $receipt['record']['id'], $receipt['token'],
                    MutationCommandStore::STATUS_CONFLICT, $response, null
                );
                $this->db->endTransaction();
                return $response;
            }
            if ($newRole !== null) {
                $active = $this->db->fetchValue(
                    "SELECT id FROM users WHERE id=:id AND is_active=1 AND account_status='active' LIMIT 1",
                    [':id' => $target]
                );
                if (!$active) {
                    throw new InvalidArgumentException('Новый участник должен быть активным пользователем');
                }
                if ($actualRole === null) {
                    $max = $this->memberLimit !== null
                        ? (int) ($this->memberLimit)($actor)
                        : (int) (new RolePolicyService($this->db))
                            ->effectiveValue($actor, 'tasks', 'max_board_members');
                    $total = (int) $this->db->fetchValue(
                        'SELECT COUNT(*) FROM task_board_members WHERE board_id=:board',
                        [':board' => $boardId]
                    ) + 2;
                    if ($max > 0 && $total > $max) {
                        throw new DomainException('Достигнут лимит участников доски', 403);
                    }
                    $this->db->execute(
                        'INSERT INTO task_board_members (board_id,user_id,role,joined_at)
                         VALUES (:board,:target,:role,:joined)',
                        [':board' => $boardId, ':target' => $target,
                         ':role' => $newRole, ':joined' => gmdate('Y-m-d H:i:s')]
                    );
                } else {
                    $this->db->execute(
                        'UPDATE task_board_members SET role=:role WHERE board_id=:board AND user_id=:target',
                        [':role' => $newRole, ':board' => $boardId, ':target' => $target]
                    );
                }
            } else {
                $this->db->execute(
                    'DELETE FROM task_board_members WHERE board_id=:board AND user_id=:target',
                    [':board' => $boardId, ':target' => $target]
                );
            }

            $this->db->execute(
                'UPDATE task_boards SET updated_at=:updated WHERE id=:id',
                [':updated' => gmdate('Y-m-d H:i:s'), ':id' => $boardId]
            );
            $response = [
                'success' => true, 'board_uid' => $boardUid, 'user_id' => $target,
                'role' => $newRole, 'replayed' => false,
            ];
            $this->commands->complete(
                $this->db, (int) $receipt['record']['id'], $receipt['token'],
                MutationCommandStore::STATUS_APPLIED, $response, null
            );
            $this->db->endTransaction();
            return $response;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->endTransaction(false);
            }
            throw $error;
        }
    }
}
