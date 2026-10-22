<?php

declare(strict_types=1);

namespace Modules\Tasks;

use Core\DatabaseManager;
use InvalidArgumentException;

/**
 * Версионированное чтение личных задач для авторизованного интерфейса.
 * Курсор содержит позицию сортировки, но не предоставляет права доступа.
 */
final class TaskReadApiService
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /** @return array{items:list<array<string,mixed>>,next_cursor:?string,has_more:bool,limit:int} */
    public function listOwned(int $userId, int $limit = 30, ?string $cursor = null): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Требуется авторизованный пользователь');
        }
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Лимит должен быть от 1 до 100');
        }

        $position = self::decodeCursor($cursor, $userId);
        $params = [':owner' => $userId];
        $filter = '';
        if ($position !== null) {
            $filter = ' AND (created_at < :created OR (created_at = :created AND id < :last_id))';
            $params[':created'] = $position['created_at'];
            $params[':last_id'] = $position['id'];
        }

        // Лимит уже проверен как целое число; идентификаторы не поступают от клиента.
        $rows = $this->db->fetchAll(
            'SELECT id,uid,title,description,status,priority,due_date,completed_at,
                    archived_at,version,created_at,updated_at
             FROM tasks
             WHERE user_id = :owner AND is_deleted = 0' . $filter .
            ' ORDER BY created_at DESC, id DESC LIMIT ' . ($limit + 1),
            $params
        );

        $hasMore = count($rows) > $limit;
        $page = array_slice($rows, 0, $limit);
        $next = null;
        if ($hasMore && $page !== []) {
            $last = $page[array_key_last($page)];
            $next = self::encodeCursor((int) $last['id'], (string) $last['created_at'], $userId);
        }

        $items = array_map(static function (array $item): array {
            unset($item['id']);
            $item['version'] = (int) $item['version'];
            return $item;
        }, $page);

        return ['items' => $items, 'next_cursor' => $next, 'has_more' => $hasMore, 'limit' => $limit];
    }

    /** @return array{v:int,id:int,created_at:string,user_id:int}|null */
    public static function decodeCursor(?string $cursor, int $userId): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        if (strlen($cursor) > 512 || preg_match('/^[a-zA-Z0-9_-]+$/D', $cursor) !== 1) {
            throw new InvalidArgumentException('Некорректный курсор Tasks');
        }
        $payload = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($payload)) {
            throw new InvalidArgumentException('Некорректный курсор Tasks');
        }
        try {
            $value = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Некорректный курсор Tasks');
        }
        if (!is_array($value)
            || array_keys($value) !== ['v', 'id', 'created_at', 'user_id']
            || ($value['v'] ?? null) !== 1
            || !is_int($value['id']) || $value['id'] <= 0
            || !is_string($value['created_at'])
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value['created_at']) !== 1
            || ($value['user_id'] ?? null) !== $userId) {
            throw new InvalidArgumentException('Курсор недействителен для этого пользователя');
        }
        return $value;
    }

    private static function encodeCursor(int $id, string $createdAt, int $userId): string
    {
        return rtrim(strtr(base64_encode(json_encode([
            'v' => 1, 'id' => $id, 'created_at' => $createdAt, 'user_id' => $userId,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
