<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use RuntimeException;
use Throwable;

final class UserLifecycleService
{
    public const DEFAULT_RETENTION_DAYS = 30;

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    public function schedule(int $userId, int $retentionDays = self::DEFAULT_RETENTION_DAYS): string
    {
        if ($userId <= 0) {
            throw new RuntimeException('Некорректный пользователь');
        }

        $retentionDays = max(1, min(365, $retentionDays));
        $now = date('Y-m-d H:i:s');
        $purgeAfter = date('Y-m-d H:i:s', time() + ($retentionDays * 86400));

        $this->db->execute(
            "UPDATE users
             SET is_active = 0,
                 account_status = 'inactive',
                 deletion_requested_at = :requested_at,
                 purge_after = :purge_after,
                 updated_at = :updated_at
             WHERE id = :id
               AND anonymized_at IS NULL",
            [
                ':requested_at' => $now,
                ':purge_after' => $purgeAfter,
                ':updated_at' => $now,
                ':id' => $userId,
            ]
        );

        return $purgeAfter;
    }

    public function cancel(int $userId): void
    {
        if ($userId <= 0) {
            throw new RuntimeException('Некорректный пользователь');
        }

        $this->db->execute(
            'UPDATE users
             SET deletion_requested_at = NULL,
                 purge_after = NULL,
                 updated_at = :updated_at
             WHERE id = :id
               AND anonymized_at IS NULL',
            [':updated_at' => date('Y-m-d H:i:s'), ':id' => $userId]
        );
    }

    /** @return array{processed:int,ids:list<int>} */
    public function purgeDue(?int $onlyUserId = null, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $where = 'purge_after IS NOT NULL
                  AND purge_after <= :now
                  AND anonymized_at IS NULL
                  AND is_active = 0
                  AND account_status = :inactive';
        $params = [
            ':now' => date('Y-m-d H:i:s'),
            ':inactive' => 'inactive',
        ];
        if ($onlyUserId !== null) {
            $where .= ' AND id = :user_id';
            $params[':user_id'] = $onlyUserId;
        }

        $rows = $this->db->fetchAll(
            'SELECT id FROM users WHERE ' . $where . ' ORDER BY purge_after ASC, id ASC LIMIT ' . $limit,
            $params
        );

        $processed = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            if ($this->anonymize($userId)) {
                $processed[] = $userId;
            }
        }

        return ['processed' => count($processed), 'ids' => $processed];
    }

    private function anonymize(int $userId): bool
    {
        $this->db->beginTransaction();
        try {
            $row = $this->db->fetchOne(
                'SELECT id,uid,username,email,avatar,is_active,account_status,purge_after,anonymized_at
                 FROM users WHERE id = :id LIMIT 1 FOR UPDATE',
                [':id' => $userId]
            );
            if (!$row
                || !empty($row['anonymized_at'])
                || (int) $row['is_active'] !== 0
                || (string) $row['account_status'] !== 'inactive'
                || empty($row['purge_after'])
                || strtotime((string) $row['purge_after']) > time()) {
                $this->db->endTransaction(false);
                return false;
            }

            $token = substr(preg_replace('/[^a-f0-9]/i', '', (string) $row['uid']) ?: 'user', 0, 12);
            $username = 'deleted_' . $userId . '_' . strtolower($token);
            $email = 'deleted+' . $userId . '+' . strtolower($token) . '@invalid.workspace.local';
            $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            if (!is_string($passwordHash) || $passwordHash === '') {
                throw new RuntimeException('Не удалось создать tombstone-пароль');
            }

            $now = date('Y-m-d H:i:s');
            $this->db->execute(
                "UPDATE users SET
                    username = :username,
                    email = :email,
                    password_hash = :password_hash,
                    firstname = :firstname,
                    patronymic = NULL,
                    lastname = :lastname,
                    phone = NULL,
                    avatar = NULL,
                    property = :property,
                    role = 888,
                    is_active = 0,
                    account_status = 'inactive',
                    deletion_requested_at = COALESCE(deletion_requested_at, :requested_at),
                    purge_after = NULL,
                    anonymized_at = :anonymized_at,
                    totp_enabled = 0,
                    totp_secret = NULL,
                    totp_last_counter = NULL,
                    totp_recovery_codes = NULL,
                    totp_confirmed_at = NULL,
                    updated_at = :updated_at
                 WHERE id = :id",
                [
                    ':username' => $username,
                    ':email' => $email,
                    ':password_hash' => $passwordHash,
                    ':firstname' => 'Удалённый',
                    ':lastname' => 'пользователь',
                    ':property' => '{}',
                    ':requested_at' => $now,
                    ':anonymized_at' => $now,
                    ':updated_at' => $now,
                    ':id' => $userId,
                ]
            );

            if ($this->tableExists('user_roles')) {
                $this->db->execute('DELETE FROM user_roles WHERE user_id = :user_id', [':user_id' => $userId]);
            }
            if ($this->tableExists('user_storage_quotas')) {
                $this->db->execute('DELETE FROM user_storage_quotas WHERE user_id = :user_id', [':user_id' => $userId]);
            }

            $this->db->endTransaction(true);
            return true;
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    private function tableExists(string $table): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1',
            [':table_name' => $table]
        ) !== null;
    }
}
