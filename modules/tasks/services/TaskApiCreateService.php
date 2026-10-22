<?php

declare(strict_types=1);

namespace Modules\Tasks;

use Core\DatabaseManager;
use App\Services\RolePolicyService;
use Core\MutationCommandStore;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Создание личной задачи — одна транзакция с платформенным журналом команд.
 */
final class TaskApiCreateService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly MutationCommandStore $commands = new MutationCommandStore()
    ) {
    }

    /** @return array<string,mixed> */
    public function create(int $owner, string $commandId, array $input): array
    {
        if ($owner <= 0) {
            throw new InvalidArgumentException('Некорректный пользователь');
        }
        $allowed = ['title', 'description', 'priority', 'due_date'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new InvalidArgumentException('Запрос содержит недопустимые поля');
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
        if (!is_string($priority) || !in_array($priority, ['low','medium','high','urgent'], true)) {
            throw new InvalidArgumentException('Некорректный приоритет');
        }
        $due = $input['due_date'] ?? null;
        if ($due !== null && (!is_string($due) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $due) !== 1)) {
            throw new InvalidArgumentException('Некорректный срок');
        }

        $payload = compact('title', 'description', 'priority', 'due');
        $fingerprint = $this->commands->requestHash($payload);
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Команда требует самостоятельную транзакцию');
        }
        $this->db->beginTransaction();
        try {
            $receipt = $this->commands->acquire(
                $this->db, 'user:' . $owner, $commandId, 'tasks',
                'task.api.create', 'task', 'new', $fingerprint
            );
            if (!$receipt['owner']) {
                $answer = $this->commands->storedResponse($receipt['record']);
                $this->db->endTransaction();
                return $answer;
            }
            $limit = (int) (new RolePolicyService())->effectiveValue($owner, 'tasks', 'max_personal_tasks');
            if ($limit > 0) {
                $count = (int) $this->db->fetchValue(
                    'SELECT COUNT(*) FROM tasks WHERE user_id=:owner AND is_deleted=0',
                    [':owner' => $owner]
                );
                if ($count >= $limit) {
                    throw new \DomainException('Достигнут лимит личных задач', 403);
                }
            }
            $uid = bin2hex(random_bytes(16));
            $now = gmdate('Y-m-d H:i:s');
            $this->db->execute(
                'INSERT INTO tasks (uid,user_id,title,description,status,priority,due_date,
                    is_deleted,created_at,updated_at)
                 VALUES (:uid,:owner,:title,:description,:status,:priority,:due,
                    0,:created,:updated)',
                [
                    ':uid' => $uid, ':owner' => $owner, ':title' => $title,
                    ':description' => trim($description), ':status' => 'pending',
                    ':priority' => $priority, ':due' => $due,
                    ':created' => $now, ':updated' => $now,
                ]
            );
            $answer = ['success' => true, 'task_uid' => $uid, 'version' => 1, 'replayed' => false];
            $this->commands->complete(
                $this->db, (int) $receipt['record']['id'], $receipt['token'],
                MutationCommandStore::STATUS_APPLIED, $answer, 1
            );
            $this->db->endTransaction();
            return $answer;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->endTransaction(false);
            }
            throw $error;
        }
    }
}
