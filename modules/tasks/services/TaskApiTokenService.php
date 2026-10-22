<?php

declare(strict_types=1);

namespace Modules\Tasks;

use Core\DatabaseManager;
use InvalidArgumentException;

/**
 * Личные ключи API Tasks. Секрет не читается из базы и не сохраняется открытым.
 * Наличие ключа не отменяет текущие права его владельца.
 */
final class TaskApiTokenService
{
    public const SCOPE_READ = 'tasks.read';
    public const SCOPE_WRITE = 'tasks.write';

    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /** @return array{id:int,token:string,scope:string,expires_at:string} */
    public function issue(int $userId, string $name, int $days = 30, string $scope = self::SCOPE_READ): array
    {
        if ($userId <= 0 || $days < 1 || $days > 90) {
            throw new InvalidArgumentException('Недопустимый владелец или срок ключа');
        }

        if (!in_array($scope, [self::SCOPE_READ, self::SCOPE_WRITE], true)) {
            throw new InvalidArgumentException('Недопустимая область полномочий ключа');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Имя ключа должно содержать от 1 до 100 символов');
        }

        $token = 'ntk1_' . bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $days * 86400);
        $result = $this->db->execute(
            'INSERT INTO task_api_tokens (user_id,name,token_hash,scope,expires_at)
             VALUES (:user_id,:name,:token_hash,:scope,:expires_at)',
            [
                ':user_id' => $userId,
                ':name' => $name,
                ':token_hash' => hash('sha256', $token),
                ':scope' => $scope,
                ':expires_at' => $expiresAt,
            ]
        );
        if ($result === false) {
            throw new \RuntimeException('Ключ API не удалось сохранить');
        }

        $row = $this->db->fetchOne(
            'SELECT id FROM task_api_tokens WHERE token_hash=:hash AND user_id=:owner LIMIT 1',
            [':hash' => hash('sha256', $token), ':owner' => $userId]
        );
        if (!is_array($row)) {
            throw new \RuntimeException('Не удалось подтвердить создание ключа API');
        }

        return [
            'id' => (int) $row['id'],
            'token' => $token,
            'scope' => $scope,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{user_id:int,scope:string,token_id:int}|null
     */
    public function authenticate(string $authorization, string $neededScope = self::SCOPE_READ): ?array
    {
        if (!in_array($neededScope, [self::SCOPE_READ, self::SCOPE_WRITE], true)
            || preg_match('/^Bearer (ntk1_[0-9a-f]{64})$/D', $authorization, $matches) !== 1) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT id,user_id,scope,expires_at,revoked_at
             FROM task_api_tokens WHERE token_hash=:hash LIMIT 1',
            [':hash' => hash('sha256', $matches[1])]
        );

        if (!$row || $row['revoked_at'] !== null
            || ((string) $row['scope'] !== $neededScope
                && !($neededScope === self::SCOPE_READ && (string) $row['scope'] === self::SCOPE_WRITE))
            || strtotime((string) $row['expires_at'] . ' UTC') <= time()) {
            return null;
        }

        return ['user_id' => (int) $row['user_id'], 'scope' => (string) $row['scope'], 'token_id' => (int) $row['id']];
    }

    /** @return list<array<string,mixed>> */
    public function listOwned(int $userId): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Требуется авторизация');
        }
        return $this->db->fetchAll(
            'SELECT id,name,scope,expires_at,revoked_at,created_at,last_used_at
             FROM task_api_tokens WHERE user_id=:owner ORDER BY id DESC LIMIT 100',
            [':owner' => $userId]
        );
    }

    public function revoke(int $userId, int $id): bool
    {
        if ($userId <= 0 || $id <= 0) {
            return false;
        }
        $exists = $this->db->fetchValue(
            'SELECT id FROM task_api_tokens WHERE id=:id AND user_id=:owner LIMIT 1',
            [':id' => $id, ':owner' => $userId]
        );
        if ($exists === null) {
            return false;
        }
        $this->db->execute(
            'UPDATE task_api_tokens SET revoked_at=COALESCE(revoked_at,:revoked_at)
             WHERE id=:id AND user_id=:owner',
            [':id' => $id, ':owner' => $userId, ':revoked_at' => gmdate('Y-m-d H:i:s')]
        );
        return true;
    }
}
