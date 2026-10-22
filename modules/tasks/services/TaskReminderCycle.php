<?php

declare(strict_types=1);

namespace Modules\Tasks;

use Core\DatabaseManager;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Единый проход напоминаний и внешней доставки.
 * Именованная блокировка БД охватывает все этапы, включая запись результата.
 */
final class TaskReminderCycle
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /**
     * Обработчики принимаются явно: это позволяет проверять блокировку
     * и отказ одного этапа без реальной отправки сообщений.
     *
     * @param callable():array<string,int> $morning
     * @param callable():array<string,int> $personal
     * @param callable():array<string,int> $board
     * @param callable():array<string,int> $delivery
     * @return array<string,mixed>
     */
    public function run(
        callable $morning,
        callable $personal,
        callable $board,
        callable $delivery
    ): array {
        $lock = $this->lockName();
        if ((int) $this->db->fetchValue('SELECT GET_LOCK(:name, 0)', [':name' => $lock]) !== 1) {
            return ['status' => 'busy', 'message' => 'Предыдущий фоновый цикл ещё выполняется'];
        }

        try {
            return $this->runLocked([
                'morning' => $morning,
                'personal' => $personal,
                'board' => $board,
                'delivery' => $delivery,
            ]);
        } finally {
            try {
                $this->db->fetchValue('SELECT RELEASE_LOCK(:name)', [':name' => $lock]);
            } catch (Throwable) {
                // При потере соединения сервер БД освобождает его именованные блокировки.
                error_log('Не удалось подтвердить освобождение блокировки фонового цикла Tasks');
            }
        }
    }

    /** @return array<string,mixed> */
    public function diagnose(): array
    {
        $last = $this->db->fetchOne(
            'SELECT id,started_at,finished_at,status,morning_published,personal_published,
                    board_published,delivery_claimed,delivery_sent,delivery_retry,
                    delivery_failed,error_stage
             FROM task_reminder_cycle_runs ORDER BY id DESC LIMIT 1'
        );
        $daily = $this->db->fetchOne(
            "SELECT COUNT(*) AS runs,
                    COALESCE(SUM(delivery_sent),0) AS sent,
                    COALESCE(SUM(delivery_retry),0) AS retry,
                    COALESCE(SUM(delivery_failed),0) AS failed
             FROM task_reminder_cycle_runs
             WHERE started_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR"
        );
        return [
            'status' => 'ok',
            'mode' => 'read_only',
            'last' => $last,
            'last_24h' => [
                'runs' => (int) ($daily['runs'] ?? 0),
                'sent' => (int) ($daily['sent'] ?? 0),
                'retry' => (int) ($daily['retry'] ?? 0),
                'failed' => (int) ($daily['failed'] ?? 0),
            ],
            'external_receipt_verified' => false,
        ];
    }

    /**
     * @param array<string,callable():array<string,int>> $jobs
     * @return array<string,mixed>
     */
    private function runLocked(array $jobs): array
    {
        $this->db->execute(
            "INSERT INTO task_reminder_cycle_runs(started_at,status)
             VALUES(UTC_TIMESTAMP(),'running')"
        );
        $runId = (int) $this->db->getPdo()->lastInsertId();
        $results = [];
        $failedStages = [];

        foreach ($jobs as $name => $job) {
            try {
                $results[$name] = $job();
            } catch (Throwable $error) {
                $failedStages[] = $name;
                $results[$name] = [];
                // Ни текст исключения, ни SQL, ни содержимое уведомления не записываются.
                error_log('Ошибка фонового этапа Tasks [' . $name . ']: ' . $error::class);
            }
        }

        if ((int) ($results['delivery']['failed'] ?? 0) > 0) {
            $failedStages[] = 'delivery_failed_jobs';
        }
        $status = $failedStages === [] ? 'ok' : 'failed';
        $stats = [
            ':id' => $runId,
            ':status' => $status,
            ':morning' => max(0, (int) ($results['morning']['published'] ?? 0)),
            ':personal' => max(0, (int) ($results['personal']['published'] ?? 0)),
            ':board' => max(0, (int) ($results['board']['published'] ?? 0)),
            ':claimed' => max(0, (int) ($results['delivery']['claimed'] ?? 0)),
            ':sent' => max(0, (int) ($results['delivery']['sent'] ?? 0)),
            ':retry' => max(0, (int) ($results['delivery']['retry'] ?? 0)),
            ':failed' => max(0, (int) ($results['delivery']['failed'] ?? 0)),
            ':error_stage' => $failedStages === [] ? null : implode(',', $failedStages),
        ];
        $this->db->execute(
            'UPDATE task_reminder_cycle_runs
             SET finished_at=UTC_TIMESTAMP(),status=:status,
                 morning_published=:morning,personal_published=:personal,
                 board_published=:board,delivery_claimed=:claimed,
                 delivery_sent=:sent,delivery_retry=:retry,delivery_failed=:failed,
                 error_stage=:error_stage
             WHERE id=:id',
            $stats
        );
        // Только обезличенные числа. Данные пользователей не выводятся.
        return [
            'status' => $status,
            'run_id' => $runId,
            'morning_published' => $stats[':morning'],
            'personal_published' => $stats[':personal'],
            'board_published' => $stats[':board'],
            'delivery_claimed' => $stats[':claimed'],
            'delivery_sent' => $stats[':sent'],
            'delivery_retry' => $stats[':retry'],
            'delivery_failed' => $stats[':failed'],
            'failed_stages' => $failedStages,
        ];
    }

    private function lockName(): string
    {
        $database = (string) $this->db->fetchValue('SELECT DATABASE()');
        if ($database === '') {
            throw new RuntimeException('Не выбрана база данных фонового обработчика');
        }
        $prefix = (string) (getenv('DBPREFIX') ?: '');
        return 'notes.tasks.reminders.' . substr(hash('sha256', $database . ':' . $prefix), 0, 24);
    }
}
