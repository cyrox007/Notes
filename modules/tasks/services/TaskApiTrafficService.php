<?php

declare(strict_types=1);

namespace Modules\Tasks;

use Core\DatabaseManager;
use InvalidArgumentException;

/**
 * Лимит и журнал обращений по идентификатору ключа без хранения секрета.
 * Счётчик изменяется одним условным запросом даже при конкурентных процессах.
 */
final class TaskApiTrafficService
{
    public const READ_PER_MINUTE = 120;
    public const WRITE_PER_MINUTE = 30;

    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /**
     * @return array{allowed:bool,retry_after:int,limit:int}
     */
    public function consume(int $tokenId, string $action, ?int $timestamp = null): array
    {
        if ($tokenId <= 0 || !in_array($action, ['read', 'create', 'update'], true)) {
            throw new InvalidArgumentException('Некорректный ключ или действие API');
        }

        $current = $timestamp ?? time();
        $window = intdiv($current, 60) * 60;
        $limit = $action === 'read' ? self::READ_PER_MINUTE : self::WRITE_PER_MINUTE;
        $start = gmdate('Y-m-d H:i:s', $window);
        $this->db->execute(
            'INSERT IGNORE INTO task_api_rate_buckets (token_id,window_start,requests)
             VALUES (:token_id,:window_start,0)',
            [':token_id' => $tokenId, ':window_start' => $start]
        );
        $changed = $this->db->execute(
            'UPDATE task_api_rate_buckets SET requests=requests+1
             WHERE token_id=:token_id AND window_start=:window_start AND requests<:max_requests',
            [':token_id' => $tokenId, ':window_start' => $start, ':max_requests' => $limit]
        );
        return [
            'allowed' => $changed === 1,
            'retry_after' => max(1, 60 - ($current - $window)),
            'limit' => $limit,
        ];
    }

    public function record(int $tokenId, int $userId, string $action, int $status): void
    {
        if ($tokenId <= 0 || $userId <= 0 || !in_array($action, ['read', 'create', 'update'], true)
            || $status < 100 || $status > 599) {
            throw new InvalidArgumentException('Недопустимая запись журнала Tasks API');
        }
        $this->db->execute(
            'INSERT INTO task_api_access_log (token_id,user_id,action,status_code,created_at)
             VALUES (:token_id,:user_id,:action,:status,:created_at)',
            [
                ':token_id' => $tokenId,
                ':user_id' => $userId,
                ':action' => $action,
                ':status' => $status,
                ':created_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    /** @return list<array<string,mixed>> */
    public function recentForOwner(int $owner, int $limit = 50): array
    {
        if ($owner <= 0 || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Некорректный запрос журнала');
        }
        return $this->db->fetchAll(
            'SELECT l.id,l.token_id,t.name AS token_name,l.action,l.status_code,l.created_at
             FROM task_api_access_log l
             INNER JOIN task_api_tokens t ON t.id=l.token_id
             WHERE l.user_id=:owner AND t.user_id=:owner_token
             ORDER BY l.id DESC LIMIT ' . $limit,
            [':owner' => $owner, ':owner_token' => $owner]
        );
    }
}
