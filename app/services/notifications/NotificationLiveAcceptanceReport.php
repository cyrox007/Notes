<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Core\DatabaseManager;
use InvalidArgumentException;

/**
 * Сводка проверки конкретного задания доставки без раскрытия адресов и секретов.
 * Фактическое получение проверяется отдельно, вне базы Notes.
 */
final class NotificationLiveAcceptanceReport
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /** @return array<string,mixed> */
    public function forJob(int $jobId): array
    {
        if ($jobId < 1) {
            throw new InvalidArgumentException('Нужен положительный идентификатор задания.');
        }

        $row = $this->db->fetchOne(
            'SELECT j.id, j.channel, j.state, j.attempts, j.last_result_code,
                    j.created_at, j.sent_at, j.updated_at,
                    e.event_name, e.category
             FROM notification_delivery_jobs j
             INNER JOIN notifications n ON n.id = j.notification_id
             INNER JOIN notification_events e ON e.id = n.event_id
             WHERE j.id = :id LIMIT 1',
            [':id' => $jobId]
        );
        if ($row === null) {
            return ['status' => 'not_found', 'job_id' => $jobId];
        }

        $attempts = $this->db->fetchAll(
            'SELECT attempt_no,result_state,result_code,attempted_at
             FROM notification_delivery_attempts
             WHERE job_id = :id ORDER BY attempt_no ASC',
            [':id' => $jobId]
        );
        $history = [];
        foreach ($attempts as $attempt) {
            $history[] = [
                'number' => (int) $attempt['attempt_no'],
                'state' => (string) $attempt['result_state'],
                'code' => (string) $attempt['result_code'],
                'at_utc' => (string) $attempt['attempted_at'],
            ];
        }

        $state = (string) $row['state'];
        return [
            'status' => 'ok',
            'mode' => 'read_only',
            'job_id' => (int) $row['id'],
            'channel' => (string) $row['channel'],
            'event_name' => (string) $row['event_name'],
            'category' => (string) $row['category'],
            'queue_state' => $state,
            'attempts_total' => (int) $row['attempts'],
            'last_code' => $row['last_result_code'] === null ? null : (string) $row['last_result_code'],
            'created_utc' => (string) $row['created_at'],
            'accepted_at_utc' => $row['sent_at'] === null ? null : (string) $row['sent_at'],
            'updated_utc' => (string) $row['updated_at'],
            'transport_accepted' => $state === 'sent',
            'recipient_receipt_verified' => false,
            'recipient_receipt_status' => 'not_verified',
            'history' => $history,
        ];
    }
}
