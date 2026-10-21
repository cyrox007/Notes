<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;

/**
 * Одноразовое разрешение на подтверждение действия.
 * Токен хранится только в виде SHA-256 и никогда не используется в GET для изменения данных.
 */
final class NotificationActionTokenStore
{
    public const TTL_SECONDS = 900;

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    public function issue(int $userId, int $notificationId, string $action): string
    {
        self::validateIdentity($userId, $notificationId, $action);
        $exists = $this->db->fetchValue(
            'SELECT id FROM notifications WHERE id = :notification AND recipient_user_id = :user LIMIT 1',
            [':notification' => $notificationId, ':user' => $userId]
        );
        if ($exists === null) {
            throw new DomainException('Уведомление недоступно', 404);
        }
        $token = bin2hex(random_bytes(32));
        $this->db->execute(
            'INSERT INTO notification_action_tokens
             (token_hash, user_id, notification_id, action_name, expires_at, created_at)
             VALUES (:hash, :user, :notification, :action, :expires, UTC_TIMESTAMP())',
            [
                ':hash' => hash('sha256', $token),
                ':user' => $userId,
                ':notification' => $notificationId,
                ':action' => $action,
                ':expires' => gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS),
            ]
        );
        return $token;
    }

    public function inspect(int $userId, string $token, string $action): ?int
    {
        self::validateToken($userId, $token, $action);
        $row = $this->db->fetchOne(
            'SELECT notification_id FROM notification_action_tokens
             WHERE token_hash = :hash AND user_id = :user AND action_name = :action
               AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP()
             LIMIT 1',
            [':hash' => hash('sha256', $token), ':user' => $userId, ':action' => $action]
        );
        return $row === null ? null : (int) $row['notification_id'];
    }

    public function consume(int $userId, int $notificationId, string $token, string $action): bool
    {
        self::validateIdentity($userId, $notificationId, $action);
        self::validateToken($userId, $token, $action);
        // Одно атомарное изменение: два конкурентных запроса не смогут
        // одновременно получить право выполнить одну команду.
        $updated = $this->db->execute(
            'UPDATE notification_action_tokens
             SET consumed_at = UTC_TIMESTAMP()
             WHERE token_hash = :hash AND user_id = :user
               AND notification_id = :notification AND action_name = :action
               AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            [
                ':hash' => hash('sha256', $token),
                ':user' => $userId,
                ':notification' => $notificationId,
                ':action' => $action,
            ]
        );
        return (int) $updated === 1;
    }

    /**
     * Удаляет ограниченный пакет только истёкших токенов.
     * Лимит явно проверен перед включением в SQL; пользовательский ввод
     * не участвует в формировании выражения запроса.
     */
    public function purgeExpired(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Размер очистки токенов должен быть от 1 до 1000');
        }

        return (int) $this->db->execute(
            'DELETE FROM notification_action_tokens
             WHERE expires_at <= UTC_TIMESTAMP()
             LIMIT ' . $limit
        );
    }

    private static function validateIdentity(int $userId, int $notificationId, string $action): void
    {
        if ($userId < 1 || $notificationId < 1 || $action !== 'complete_task') {
            throw new InvalidArgumentException('Недопустимое действие уведомления');
        }
    }

    private static function validateToken(int $userId, string $token, string $action): void
    {
        if ($userId < 1 || $action !== 'complete_task'
            || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Недействительный формат подтверждения');
        }
    }
}
