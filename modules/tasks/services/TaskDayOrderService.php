<?php

declare(strict_types=1);

namespace Modules\Tasks;

use Core\DatabaseManager;
use Core\MutationCommandStore;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Очередь дня имеет собственную версию, не меняющую версии задач.
 * Упорядочение и квитанция команды сохраняются в одной транзакции.
 */
final class TaskDayOrderService
{
    private const MAX_DAY_TASKS = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly MutationCommandStore $commands = new MutationCommandStore()
    ) {
    }

    public function version(int $userId, string $queueDate): int
    {
        $value = $this->db->fetchValue(
            'SELECT version FROM task_personal_day_queues
             WHERE user_id=:user_id AND queue_date=:queue_date',
            [':user_id' => $userId, ':queue_date' => $queueDate]
        );
        return $value === null ? 1 : (int) $value;
    }

    /** @return array<string,mixed> */
    public function move(
        int $userId,
        string $day,
        string $uid,
        string $direction,
        int $expectedVersion,
        string $commandId,
        ?DateTimeImmutable $now = null
    ): array {
        if ($userId <= 0 || $expectedVersion < 1 || !in_array($day, ['today', 'tomorrow'], true)
            || !in_array($direction, ['up', 'down'], true) || preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1
            || trim($commandId) === ''
        ) {
            throw new InvalidArgumentException('Некорректная команда порядка дневных задач');
        }
        $now ??= new DateTimeImmutable('now');
        $bounds = TaskPersonalQueue::boundaries($now);
        $start = $day === 'today' ? $bounds['today'] : $bounds['tomorrow'];
        $end = $day === 'today' ? $bounds['tomorrow'] : $bounds['after_tomorrow'];
        $queueDate = substr($start, 0, 10);

        if ($this->db->inTransaction()) {
            throw new RuntimeException('Для перестановки требуется самостоятельная транзакция');
        }
        $this->db->beginTransaction();
        try {
            $receipt = $this->commands->acquire(
                $this->db, 'user:' . $userId, $commandId, 'tasks',
                'task.day_reorder', 'task_day_queue', $queueDate,
                $this->commands->requestHash([
                    'day' => $day, 'uid' => $uid, 'direction' => $direction,
                    'expected_version' => $expectedVersion,
                ])
            );
            if (!$receipt['owner']) {
                $response = $this->commands->storedResponse($receipt['record']);
                $this->db->endTransaction();
                return $response;
            }

            $this->db->execute(
                'INSERT INTO task_personal_day_queues (user_id,queue_date,version)
                 VALUES (:user_id,:queue_date,1)
                 ON DUPLICATE KEY UPDATE version=version',
                [':user_id' => $userId, ':queue_date' => $queueDate]
            );
            $queue = $this->db->prepare(
                'SELECT version FROM task_personal_day_queues
                 WHERE user_id=:user_id AND queue_date=:queue_date FOR UPDATE'
            );
            $queue->execute([':user_id' => $userId, ':queue_date' => $queueDate]);
            $actualVersion = (int) $queue->fetchColumn();

            if ($actualVersion !== $expectedVersion) {
                $response = [
                    'success' => false, 'error' => 'version_conflict',
                    'message' => 'Порядок уже изменился в другой вкладке. Обновите список.',
                    'version' => $actualVersion,
                ];
                return $this->complete($receipt, $response,
                    MutationCommandStore::STATUS_CONFLICT, $actualVersion);
            }

            $statement = $this->db->prepare(
                'SELECT t.id,t.uid,p.position FROM tasks t
                 LEFT JOIN task_personal_day_positions p
                    ON p.user_id=t.user_id AND p.queue_date=:queue_date AND p.task_id=t.id
                 WHERE t.user_id=:user_id AND t.is_deleted=0 AND t.archived_at IS NULL
                   AND t.status NOT IN (\'completed\',\'cancelled\')
                   AND t.due_date >= :day_start AND t.due_date < :day_end
                 ORDER BY (p.position IS NULL) ASC,p.position ASC,t.due_date ASC,t.id ASC
                 LIMIT 501 FOR UPDATE'
            );
            $statement->execute([
                ':user_id' => $userId, ':queue_date' => $queueDate,
                ':day_start' => $start, ':day_end' => $end,
            ]);
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
            if (count($rows) > self::MAX_DAY_TASKS) {
                throw new InvalidArgumentException('Для ручного порядка допускается не более 500 задач в одном дне');
            }

            $selected = array_search($uid, array_column($rows, 'uid'), true);
            if ($selected === false) {
                $response = [
                    'success' => false, 'error' => 'queue_changed',
                    'message' => 'Задача больше не входит в дневную очередь. Обновите список.',
                    'version' => $actualVersion,
                ];
                return $this->complete($receipt, $response,
                    MutationCommandStore::STATUS_CONFLICT, $actualVersion);
            }

            $neighbor = $selected + ($direction === 'up' ? -1 : 1);
            if (!isset($rows[$neighbor])) {
                return $this->complete($receipt, [
                    'success' => true, 'version' => $actualVersion,
                    'unchanged' => true, 'replayed' => false,
                ], MutationCommandStore::STATUS_APPLIED, $actualVersion);
            }

            $position = max(array_map(
                static fn (array $row): int => (int) ($row['position'] ?? 0), $rows
            ));
            foreach ($rows as &$row) {
                if ($row['position'] !== null) {
                    continue;
                }
                $row['position'] = ++$position;
                $this->db->execute(
                    'INSERT INTO task_personal_day_positions (user_id,queue_date,task_id,position)
                     VALUES (:user_id,:queue_date,:task_id,:position)',
                    [':user_id' => $userId, ':queue_date' => $queueDate,
                     ':task_id' => (int) $row['id'], ':position' => $position]
                );
            }
            unset($row);

            foreach ([$selected, $neighbor] as $index) {
                $other = $index === $selected ? $neighbor : $selected;
                $this->db->execute(
                    'UPDATE task_personal_day_positions SET position=:position
                     WHERE user_id=:user_id AND queue_date=:queue_date AND task_id=:task_id',
                    [':position' => (int) $rows[$other]['position'],
                     ':user_id' => $userId, ':queue_date' => $queueDate,
                     ':task_id' => (int) $rows[$index]['id']]
                );
            }
            $this->db->execute(
                'UPDATE task_personal_day_queues SET version=version+1
                 WHERE user_id=:user_id AND queue_date=:queue_date AND version=:expected',
                [':user_id' => $userId, ':queue_date' => $queueDate,
                 ':expected' => $actualVersion]
            );
            return $this->complete($receipt, [
                'success' => true, 'version' => $actualVersion + 1,
                'task_uid' => $uid, 'direction' => $direction,
                'unchanged' => false, 'replayed' => false,
            ], MutationCommandStore::STATUS_APPLIED, $actualVersion + 1);
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->endTransaction(false);
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function complete(array $receipt, array $response, string $status, int $version): array
    {
        $this->commands->complete(
            $this->db, (int) $receipt['record']['id'], $receipt['token'],
            $status, $response, $version
        );
        $this->db->endTransaction();
        return $response;
    }
}
