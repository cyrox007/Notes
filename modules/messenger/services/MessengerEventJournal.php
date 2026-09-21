<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use JsonException;
use RuntimeException;

final class MessengerEventJournal
{
    private const DEFAULT_RETENTION_SECONDS = 600;
    private const MIN_RETENTION_SECONDS = 60;
    private const MAX_RETENTION_SECONDS = 86400;
    private const MAX_BATCH = 200;

    private static int $lastCleanupAt = 0;

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    public function cursorForUserId(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        return (int) ($this->db->fetchValue(
            'SELECT COALESCE(MAX(id), 0) FROM messenger_transport_events WHERE user_id = :user_id',
            [':user_id' => $userId]
        ) ?? 0);
    }

    public function publishToUserUid(string $userUid, array $payload): ?int
    {
        $userUid = trim($userUid);
        if ($userUid === '') {
            return null;
        }

        $userId = (int) ($this->db->fetchValue(
            'SELECT id FROM users WHERE uid = :uid AND is_active = 1 AND account_status = "active" LIMIT 1',
            [':uid' => $userUid]
        ) ?? 0);

        if ($userId <= 0) {
            return null;
        }

        return $this->publishToUserId($userId, $payload);
    }

    public function publishToUserId(int $userId, array $payload): int
    {
        if ($userId <= 0) {
            throw new RuntimeException('Messenger event recipient is invalid');
        }

        try {
            $encoded = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Messenger event payload is not JSON-serializable', 0, $e);
        }

        $now = time();
        $expiresAt = date(
            'Y-m-d H:i:s',
            $now + self::retentionSecondsForPayload($payload)
        );
        $this->db->execute(
            'INSERT INTO messenger_transport_events (user_id,payload,created_at,expires_at)
             VALUES (:user_id,:payload,:created_at,:expires_at)',
            [
                ':user_id' => $userId,
                ':payload' => $encoded,
                ':created_at' => date('Y-m-d H:i:s', $now),
                ':expires_at' => $expiresAt,
            ]
        );

        $eventId = (int) $this->db->getPdo()->lastInsertId();
        if ($eventId <= 0) {
            throw new RuntimeException('Messenger event journal did not return an event id');
        }

        $this->cleanupExpired();
        return $eventId;
    }

    /**
     * @return array{events:list<array<string,mixed>>,cursor:int}
     */
    public function readSince(int $userId, int $cursor, int $limit = 100): array
    {
        if ($userId <= 0) {
            return ['events' => [], 'cursor' => max(0, $cursor)];
        }

        $cursor = max(0, $cursor);
        $limit = max(1, min(self::MAX_BATCH, $limit));
        $rows = $this->db->fetchAll(
            'SELECT id,payload
             FROM messenger_transport_events
             WHERE user_id = :user_id
               AND id > :cursor
               AND expires_at >= NOW()
             ORDER BY id ASC
             LIMIT ' . $limit,
            [
                ':user_id' => $userId,
                ':cursor' => $cursor,
            ]
        );

        $events = [];
        $nextCursor = $cursor;
        foreach ($rows as $row) {
            $eventId = (int) ($row['id'] ?? 0);
            if ($eventId <= 0) {
                continue;
            }
            $nextCursor = max($nextCursor, $eventId);

            try {
                $payload = json_decode((string) ($row['payload'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                error_log('Messenger event journal payload decode failed for event ' . $eventId . ': ' . $e->getMessage());
                continue;
            }

            if (!is_array($payload)) {
                continue;
            }
            $payload['event_id'] = $eventId;
            $events[] = $payload;
        }

        return ['events' => $events, 'cursor' => $nextCursor];
    }

    /** @param array<string,mixed> $payload */
    private static function retentionSecondsForPayload(array $payload): int
    {
        $action = (string) ($payload['action'] ?? '');
        if (in_array($action, ['activity', 'user_typing', 'typing_stop'], true)) {
            return 15;
        }

        return self::retentionSeconds();
    }

    public static function retentionSeconds(): int
    {
        $raw = trim((string) (getenv('MESSENGER_EVENT_RETENTION_SECONDS') ?: ''));
        $value = ctype_digit($raw) ? (int) $raw : self::DEFAULT_RETENTION_SECONDS;
        return max(self::MIN_RETENTION_SECONDS, min(self::MAX_RETENTION_SECONDS, $value));
    }

    private function cleanupExpired(): void
    {
        $now = time();
        if (self::$lastCleanupAt > 0 && ($now - self::$lastCleanupAt) < 60) {
            return;
        }
        self::$lastCleanupAt = $now;

        try {
            $this->db->execute(
                'DELETE FROM messenger_transport_events WHERE expires_at < NOW() ORDER BY id ASC LIMIT 500'
            );
        } catch (\Throwable $e) {
            error_log('Messenger event journal cleanup failed: ' . $e->getMessage());
        }
    }
}
