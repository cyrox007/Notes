<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use UUID;

final class MessengerSavedService
{
    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @return array{uid:string,type:string,name:string,title:string,participants:list<array<string,mixed>>,partner:null,avatar:null} */
    public function getOrCreate(string $userUid): array
    {
        $this->db->beginTransaction();
        try {
            $user = $this->db->fetchOne(
                'SELECT id, uid
                 FROM users
                 WHERE uid = :uid AND is_active = 1
                 LIMIT 1
                 FOR UPDATE',
                [':uid' => $userUid]
            );
            if (!$user) {
                throw new DomainException('Пользователь не найден или заблокирован');
            }

            $existing = $this->db->fetchOne(
                'SELECT d.uid
                 FROM dialogs d
                 INNER JOIN user_to_dialogs utd
                    ON utd.dialog_id = d.id
                   AND utd.user_id = :user_id
                   AND utd.is_deleted = 0
                 WHERE d.type = "saved"
                   AND d.created_by = :created_by
                 ORDER BY d.id ASC
                 LIMIT 1',
                [
                    ':user_id' => (int) $user['id'],
                    ':created_by' => (int) $user['id'],
                ]
            );

            if ($existing) {
                $dialogUid = (string) $existing['uid'];
                $this->db->endTransaction(true);
                return (new MessengerService($this->db))->getDialogInfo($userUid, $dialogUid);
            }

            $dialogUid = UUID::v4();
            $now = date('Y-m-d H:i:s');
            $this->db->execute(
                'INSERT INTO dialogs (uid, type, name, created_by, created_at, updated_at)
                 VALUES (:uid, "saved", :name, :created_by, :created_at, :updated_at)',
                [
                    ':uid' => $dialogUid,
                    ':name' => 'Сохранённые сообщения',
                    ':created_by' => (int) $user['id'],
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]
            );
            $dialogId = (int) $this->db->getPdo()->lastInsertId();
            $this->db->execute(
                'INSERT INTO user_to_dialogs (
                    dialog_id, user_id, role, joined_at,
                    last_delivered_message_id, last_read_message_id, is_deleted
                 ) VALUES (
                    :dialog_id, :user_id, "owner", :joined_at,
                    NULL, NULL, 0
                 )',
                [
                    ':dialog_id' => $dialogId,
                    ':user_id' => (int) $user['id'],
                    ':joined_at' => $now,
                ]
            );
            $this->db->endTransaction(true);
        } catch (\Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }

        return (new MessengerService($this->db))->getDialogInfo($userUid, $dialogUid);
    }
}
