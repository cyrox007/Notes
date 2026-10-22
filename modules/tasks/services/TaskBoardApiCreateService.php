<?php

declare(strict_types=1);

namespace Modules\Tasks;

use App\Services\TaskBoardService;
use Core\DatabaseManager;
use Core\MutationCommandStore;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Создание командной задачи и квитанции повтора в одной транзакции.
 * Права участника проверяются при каждом вызове, в том числе при повторе.
 */
final class TaskBoardApiCreateService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly MutationCommandStore $commands = new MutationCommandStore()
    ) {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(int $actor, string $boardUid, string $commandId, array $input): array
    {
        if ($actor < 1 || preg_match('/^[a-f0-9]{32}$/D', $boardUid) !== 1) {
            throw new InvalidArgumentException('Некорректный участник или доска');
        }
        if (array_diff(array_keys($input), ['title', 'description', 'priority', 'due_date']) !== []) {
            throw new InvalidArgumentException('Переданы недопустимые поля задачи');
        }
        $title = $input['title'] ?? null;
        if (!is_string($title) || ($title = trim($title)) === '' || mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Название задачи должно содержать от 1 до 255 символов');
        }
        $description = $input['description'] ?? '';
        if (!is_string($description) || mb_strlen($description) > 10000) {
            throw new InvalidArgumentException('Некорректное описание');
        }
        $priority = $input['priority'] ?? 'medium';
        if (!is_string($priority) || !in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
            throw new InvalidArgumentException('Некорректный приоритет');
        }
        $due = $input['due_date'] ?? null;
        if ($due !== null) {
            if (!is_string($due)) {
                throw new InvalidArgumentException('Некорректная дата');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $due);
            if (!$date || $date->format('Y-m-d H:i:s') !== $due) {
                throw new InvalidArgumentException('Некорректная дата');
            }
        }

        $payload = ['title' => $title, 'description' => trim($description), 'priority' => $priority, 'due_date' => $due];
        $fingerprint = $this->commands->requestHash($payload);
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Создание требует самостоятельной транзакции');
        }

        $this->db->beginTransaction();
        try {
            $boards = new TaskBoardService($this->db);
            $board = $boards->boardForUser($actor, $boardUid);
            if (empty($board['can_write'])) {
                throw new DomainException('Недостаточно прав для создания задачи на доске', 403);
            }
            // Блокируем родительскую доску, чтобы изменение её состояния не обошло проверку ACL.
            $lock = $this->db->prepare('SELECT id,is_archived FROM task_boards WHERE id=:id FOR UPDATE');
            $lock->execute([':id' => (int) $board['id']]);
            $current = $lock->fetch(\PDO::FETCH_ASSOC);
            if (!$current || (int) $current['is_archived'] !== 0) {
                throw new DomainException('Доска недоступна', 404);
            }

            $receipt = $this->commands->acquire(
                $this->db, 'user:' . $actor, $commandId,
                'tasks', 'task.api.board.create', 'task_board_item', $boardUid, $fingerprint
            );
            if (!$receipt['owner']) {
                $response = $this->commands->storedResponse($receipt['record']);
                $this->db->endTransaction();
                return $response;
            }

            $uid = bin2hex(random_bytes(16));
            $now = gmdate('Y-m-d H:i:s');
            $this->db->execute(
                'INSERT INTO task_board_items
                    (uid,board_id,creator_user_id,title,description,status,priority,due_date,
                     is_deleted,version,created_at,updated_at)
                 VALUES
                    (:uid,:board,:actor,:title,:description,:status,:priority,:due,
                     0,1,:created,:updated)',
                [
                    ':uid' => $uid, ':board' => (int) $board['id'], ':actor' => $actor,
                    ':title' => $title, ':description' => trim($description),
                    ':status' => 'pending', ':priority' => $priority, ':due' => $due,
                    ':created' => $now, ':updated' => $now,
                ]
            );
            $this->db->execute('UPDATE task_boards SET updated_at=:updated WHERE id=:id',
                [':updated' => $now, ':id' => (int) $board['id']]);
            $response = [
                'success' => true, 'board_uid' => $boardUid, 'task_uid' => $uid,
                'version' => 1, 'replayed' => false,
            ];
            $this->commands->complete(
                $this->db, (int) $receipt['record']['id'], $receipt['token'],
                MutationCommandStore::STATUS_APPLIED, $response, 1
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
