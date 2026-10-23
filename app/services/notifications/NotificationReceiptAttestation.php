<?php
declare(strict_types=1);

namespace App\Services\Notifications;

use Core\DatabaseManager;
use DomainException;

/**
 * Фиксирует исключительно ручное свидетельство оператора о получении.
 * Не делает вывод о фактическом получении из статуса транспорта.
 */
final class NotificationReceiptAttestation
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function record(int $jobId, int $operatorId, bool $received): void
    {
        if ($jobId < 1 || $operatorId < 1) {
            throw new DomainException('Требуются действительные номера задания и оператора');
        }
        $operator = $this->db->fetchValue(
            "SELECT COUNT(*) FROM users u WHERE u.id=:operator AND u.is_active=1
             AND u.account_status='active' AND u.deletion_requested_at IS NULL
             AND u.anonymized_at IS NULL",
            [':operator' => $operatorId]
        );
        if ((int) $operator !== 1) {
            throw new DomainException('Учётная запись оператора недоступна');
        }
        $job = $this->db->fetchOne(
            'SELECT state FROM notification_delivery_jobs WHERE id=:job LIMIT 1',
            [':job' => $jobId]
        );
        if ($job === null) {
            throw new DomainException('Задание доставки не найдено');
        }
        if ($received && $job['state'] !== 'sent') {
            throw new DomainException('Нельзя подтвердить получение до принятия внешним каналом');
        }
        $this->db->execute(
            'INSERT INTO notification_receipt_attestations
             (job_id,operator_user_id,receipt_state,confirmed_at,updated_at)
             VALUES (:job,:operator,:state,UTC_TIMESTAMP(),UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE operator_user_id=VALUES(operator_user_id),
                receipt_state=VALUES(receipt_state),confirmed_at=VALUES(confirmed_at),
                updated_at=VALUES(updated_at)',
            [
                ':job' => $jobId,
                ':operator' => $operatorId,
                ':state' => $received ? 'received' : 'not_received',
            ]
        );
    }
}
