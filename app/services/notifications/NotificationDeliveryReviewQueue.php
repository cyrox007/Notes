<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Core\DatabaseManager;

/**
 * Два ограниченных списка для ручной приёмки: проблемы — первыми,
 * затем сообщения, принятые каналом, но ещё не проверенные оператором.
 * Адреса, тексты уведомлений и параметры каналов не читаются.
 */
final class NotificationDeliveryReviewQueue
{
    private const PAGE_SIZE = 20;

    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /** @return array{attention:list<array<string,mixed>>,unverified:list<array<string,mixed>>} */
    public function overview(): array
    {
        $fields = 'SELECT j.id,j.channel,j.state,j.attempts,j.last_result_code,
                          j.updated_at,r.receipt_state
                   FROM notification_delivery_jobs j
                   LEFT JOIN notification_receipt_attestations r ON r.job_id=j.id';

        // Окончательная ошибка или отметка «не получено» требуют разбирательства.
        // Повторяемые задания не объявляем окончательно не доставленными.
        $attention = $this->db->fetchAll(
            $fields . " WHERE j.state='failed' OR r.receipt_state='not_received'
                        ORDER BY j.updated_at DESC,j.id DESC LIMIT " . self::PAGE_SIZE
        );

        // Этот список не подтверждает получение: он лишь напоминает оператору
        // проверить контролируемый адрес или устройство.
        $unverified = $this->db->fetchAll(
            $fields . " WHERE j.state='sent' AND r.job_id IS NULL
                        ORDER BY j.updated_at DESC,j.id DESC LIMIT " . self::PAGE_SIZE
        );

        return [
            'attention' => $this->normalize($attention),
            'unverified' => $this->normalize($unverified),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function normalize(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'channel' => (string) $row['channel'],
                'state' => (string) $row['state'],
                'attempts' => (int) $row['attempts'],
                'result_code' => $row['last_result_code'] === null
                    ? null : (string) $row['last_result_code'],
                'updated_utc' => (string) $row['updated_at'],
                'receipt_state' => $row['receipt_state'] === null
                    ? 'not_verified' : (string) $row['receipt_state'],
            ];
        }
        return $result;
    }
}
