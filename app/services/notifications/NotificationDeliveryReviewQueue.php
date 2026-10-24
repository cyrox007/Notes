<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Core\DatabaseManager;
use InvalidArgumentException;

/**
 * Два ограниченных списка для ручной приёмки: проблемы — первыми,
 * затем сообщения, принятые каналом, но ещё не проверенные оператором.
 * Адреса, тексты уведомлений и параметры каналов не читаются.
 */
final class NotificationDeliveryReviewQueue
{
    private const PAGE_SIZE = 20;
    private const MAX_PAGE = 1000;

    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /** @return array<string,mixed> */
    public function overview(int $attentionPage = 1, int $unverifiedPage = 1): array
    {
        $this->validatePage($attentionPage);
        $this->validatePage($unverifiedPage);
        $fields = 'SELECT j.id,j.channel,j.state,j.attempts,j.last_result_code,
                          j.updated_at,r.receipt_state
                   FROM notification_delivery_jobs j
                   LEFT JOIN notification_receipt_attestations r ON r.job_id=j.id';

        // Окончательная ошибка или отметка «не получено» требуют разбирательства.
        // Повторяемые задания не объявляем окончательно не доставленными.
        $attention = $this->page(
            $fields,
            " WHERE j.state='failed' OR r.receipt_state='not_received'",
            $attentionPage
        );

        // Подтверждение транспортом само по себе не доказывает получение.
        $unverified = $this->page(
            $fields,
            " WHERE j.state='sent' AND r.job_id IS NULL",
            $unverifiedPage
        );

        return [
            'attention' => $attention['items'],
            'unverified' => $unverified['items'],
            'pagination' => [
                'attention' => $attention['paging'],
                'unverified' => $unverified['paging'],
            ],
        ];
    }

    private function validatePage(int $page): void
    {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new InvalidArgumentException('Номер страницы должен быть от 1 до 1000');
        }
    }

    /** @return array{items:list<array<string,mixed>>,paging:array<string,int|bool>} */
    private function page(string $fields, string $where, int $page): array
    {
        $offset = ($page - 1) * self::PAGE_SIZE;
        // Читаем на одну строку больше, чтобы узнать, есть ли следующая
        // страница, не выполняя тяжёлый COUNT по всей очереди.
        $rows = $this->db->fetchAll(
            $fields . $where . ' ORDER BY j.updated_at DESC,j.id DESC LIMIT '
            . (self::PAGE_SIZE + 1) . ' OFFSET ' . $offset
        );
        $hasNext = count($rows) > self::PAGE_SIZE;

        return [
            'items' => $this->normalize(array_slice($rows, 0, self::PAGE_SIZE)),
            'paging' => [
                'page' => $page,
                'has_previous' => $page > 1,
                'has_next' => $hasNext,
            ],
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
