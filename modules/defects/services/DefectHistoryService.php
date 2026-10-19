<?php

declare(strict_types=1);

namespace Modules\Defects\Services;

use Core\DatabaseManager;
use InvalidArgumentException;

/**
 * Предметная история изменений. Не открывает собственных соединений или
 * транзакций: запись вызывается внутри транзакции изменяющего сервиса.
 */
final class DefectHistoryService
{
    private const array TYPES = ['defect', 'area', 'category', 'object', 'service'];
    private const array EVENTS = ['created', 'closed', 'updated', 'archived', 'restored'];

    public function __construct(private readonly DatabaseManager $database)
    {
    }

    /** @param array<string,mixed> $details */
    public function record(
        string $type,
        int $entityId,
        string $event,
        ?int $userId,
        array $details,
        ?string $commandId = null
    ): void {
        $this->assertTarget($type, $entityId);
        if (!in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException('Неизвестное событие истории');
        }
        if ($userId !== null && $userId < 1) {
            throw new InvalidArgumentException('Некорректный автор изменения');
        }
        if ($commandId !== null && preg_match('/^[A-Za-z0-9_-]{16,96}$/D', $commandId) !== 1) {
            throw new InvalidArgumentException('Некорректный идентификатор операции');
        }

        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 24576) {
            throw new InvalidArgumentException('Событие истории слишком велико');
        }

        $this->database->execute(
            'INSERT INTO defect_history
                (entity_type, entity_id, event_type, actor_user_id, command_id, payload_json, created_at)
             VALUES (:type, :entity_id, :event, :user_id, :command_id, :details, :created_at)',
            [
                'type' => $type,
                'entity_id' => $entityId,
                'event' => $event,
                'user_id' => $userId,
                'command_id' => $commandId,
                'details' => $json,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * @return array{events:list<array<string,mixed>>,next_cursor:int|null}
     */
    public function list(string $type, int $entityId, int $limit = 40, int $afterId = 0): array
    {
        $this->assertTarget($type, $entityId);
        if ($limit < 1 || $limit > 100 || $afterId < 0) {
            throw new InvalidArgumentException('Недопустимая страница истории');
        }

        $rows = $this->database->fetchAll(
            'SELECT id,entity_type,entity_id,event_type,actor_user_id,command_id,payload_json,created_at
             FROM defect_history
             WHERE entity_type=:type AND entity_id=:entity_id AND id>:after_id
             ORDER BY id ASC LIMIT ' . ($limit + 1),
            ['type' => $type, 'entity_id' => $entityId, 'after_id' => $afterId]
        );

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['entity_id'] = (int) $row['entity_id'];
            $row['actor_user_id'] = $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'];
            $row['details'] = json_decode((string) $row['payload_json'], true, 32, JSON_THROW_ON_ERROR);
            unset($row['payload_json']);
        }
        unset($row);

        return [
            'events' => $rows,
            'next_cursor' => $hasMore ? (int) end($rows)['id'] : null,
        ];
    }

    private function assertTarget(string $type, int $entityId): void
    {
        if (!in_array($type, self::TYPES, true) || $entityId < 1) {
            throw new InvalidArgumentException('Неизвестная запись истории');
        }
    }
}
