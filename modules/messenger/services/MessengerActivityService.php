<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;

final class MessengerActivityService
{
    private const TTL_SECONDS = 6;

    /** @var list<string> */
    private const ALLOWED_TYPES = [
        'typing',
        'recording_voice',
        'recording_video',
        'uploading_image',
        'uploading_voice',
        'uploading_audio',
        'uploading_video',
        'uploading_document',
        'uploading_file',
    ];

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    public function publish(string $userUid, string $dialogUid, string $activity, bool $active): void
    {
        if (!in_array($activity, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException('Неизвестный тип активности Messenger');
        }

        [$userId, $dialogId] = $this->membership($userUid, $dialogUid);
        $this->cleanupExpired();

        if (!$active) {
            $this->db->execute(
                'DELETE FROM messenger_activity
                 WHERE dialog_id = :dialog_id
                   AND user_id = :user_id
                   AND activity = :activity',
                [
                    ':dialog_id' => $dialogId,
                    ':user_id' => $userId,
                    ':activity' => $activity,
                ]
            );
            return;
        }

        $this->db->execute(
            'INSERT INTO messenger_activity
                (dialog_id, user_id, activity, expires_at, updated_at)
             VALUES
                (:dialog_id, :user_id, :activity,
                 DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL ' . self::TTL_SECONDS . ' SECOND),
                 CURRENT_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE
                expires_at = VALUES(expires_at),
                updated_at = CURRENT_TIMESTAMP(3)',
            [
                ':dialog_id' => $dialogId,
                ':user_id' => $userId,
                ':activity' => $activity,
            ]
        );
    }

    /** @return list<array{user_uid:string,activity:string}> */
    public function snapshot(string $viewerUid, string $dialogUid): array
    {
        [$viewerId, $dialogId] = $this->membership($viewerUid, $dialogUid);
        $this->cleanupExpired();

        $rows = $this->db->fetchAll(
            'SELECT u.uid AS user_uid, ma.activity
             FROM messenger_activity ma
             INNER JOIN users u ON u.id = ma.user_id
             WHERE ma.dialog_id = :dialog_id
               AND ma.user_id <> :viewer_id
               AND ma.expires_at > CURRENT_TIMESTAMP(3)
               AND u.is_active = 1
             ORDER BY ma.updated_at DESC, ma.activity ASC',
            [
                ':dialog_id' => $dialogId,
                ':viewer_id' => $viewerId,
            ]
        );

        return array_values(array_map(
            static fn (array $row): array => [
                'user_uid' => (string) $row['user_uid'],
                'activity' => (string) $row['activity'],
            ],
            $rows
        ));
    }

    private function cleanupExpired(): void
    {
        $this->db->execute(
            'DELETE FROM messenger_activity WHERE expires_at <= CURRENT_TIMESTAMP(3)'
        );
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
            [
                ':user_uid' => trim($userUid),
                ':dialog_uid' => trim($dialogUid),
            ]
        );

        if (!$row) {
            throw new DomainException('Нет доступа к диалогу');
        }

        return [(int) $row['user_id'], (int) $row['dialog_id']];
    }
}
