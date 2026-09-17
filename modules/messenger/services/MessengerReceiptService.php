<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;

final class MessengerReceiptService
{
    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @return list<array<string,mixed>> */
    public function listCursors(string $userUid, string $dialogUid): array
    {
        [, $dialogId] = $this->membership($userUid, $dialogUid);

        return $this->db->fetchAll(
            'SELECT
                u.uid AS user_uid,
                GREATEST(
                    COALESCE(utd.last_delivered_message_id, 0),
                    COALESCE(utd.last_read_message_id, 0)
                ) AS last_delivered_message_id,
                COALESCE(utd.last_read_message_id, 0) AS last_read_message_id
             FROM user_to_dialogs utd
             INNER JOIN users u ON u.id = utd.user_id
             WHERE utd.dialog_id = :dialog_id
               AND utd.is_deleted = 0
               AND u.is_active = 1
             ORDER BY utd.id ASC',
            [':dialog_id' => $dialogId]
        );
    }

    /** @return array{dialog_uid:string,user_uid:string,last_delivered_message_id:int} */
    public function markDelivered(string $userUid, string $dialogUid, string $messageUid): array
    {
        [$userId, $dialogId] = $this->membership($userUid, $dialogUid);

        $message = $this->db->fetchOne(
            'SELECT id
             FROM messages
             WHERE uid = :message_uid
               AND dialog_id = :dialog_id
               AND is_deleted = 0
             LIMIT 1',
            [
                ':message_uid' => $messageUid,
                ':dialog_id' => $dialogId,
            ]
        );

        if (!$message) {
            throw new InvalidArgumentException('Сообщение для подтверждения доставки не найдено');
        }

        $messageId = (int) $message['id'];
        $this->db->execute(
            'UPDATE user_to_dialogs
             SET last_delivered_message_id = GREATEST(
                 COALESCE(last_delivered_message_id, 0),
                 COALESCE(last_read_message_id, 0),
                 :message_id
             )
             WHERE dialog_id = :dialog_id
               AND user_id = :user_id
               AND is_deleted = 0',
            [
                ':message_id' => $messageId,
                ':dialog_id' => $dialogId,
                ':user_id' => $userId,
            ]
        );

        $cursor = (int) ($this->db->fetchValue(
            'SELECT GREATEST(
                COALESCE(last_delivered_message_id, 0),
                COALESCE(last_read_message_id, 0)
             )
             FROM user_to_dialogs
             WHERE dialog_id = :dialog_id AND user_id = :user_id
             LIMIT 1',
            [':dialog_id' => $dialogId, ':user_id' => $userId]
        ) ?? $messageId);

        return [
            'dialog_uid' => $dialogUid,
            'user_uid' => $userUid,
            'last_delivered_message_id' => $cursor,
        ];
    }

    /** @return list<string> */
    public function participantUids(string $userUid, string $dialogUid): array
    {
        [, $dialogId] = $this->membership($userUid, $dialogUid);
        $rows = $this->db->fetchAll(
            'SELECT u.uid
             FROM user_to_dialogs utd
             INNER JOIN users u ON u.id = utd.user_id
             WHERE utd.dialog_id = :dialog_id
               AND utd.is_deleted = 0
               AND u.is_active = 1',
            [':dialog_id' => $dialogId]
        );

        return array_values(array_map(
            static fn (array $row): string => (string) $row['uid'],
            $rows
        ));
    }

    /** @return array{0:int,1:int} */
    private function membership(string $userUid, string $dialogUid): array
    {
        $row = $this->db->fetchOne(
            'SELECT u.id AS user_id, d.id AS dialog_id
             FROM users u
             INNER JOIN user_to_dialogs utd ON utd.user_id = u.id AND utd.is_deleted = 0
             INNER JOIN dialogs d ON d.id = utd.dialog_id
             WHERE u.uid = :user_uid
               AND u.is_active = 1
               AND d.uid = :dialog_uid
             LIMIT 1',
            [':user_uid' => $userUid, ':dialog_uid' => $dialogUid]
        );

        if (!$row) {
            throw new DomainException('Нет доступа к диалогу');
        }

        return [(int) $row['user_id'], (int) $row['dialog_id']];
    }
}
