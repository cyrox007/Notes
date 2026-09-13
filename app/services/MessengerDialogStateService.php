<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;

final class MessengerDialogStateService
{
    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @return array<string,mixed> */
    public function setPinned(string $userUid, string $dialogUid, bool $pinned): array
    {
        [$userId, $dialogId] = $this->membership($userUid, $dialogUid);
        $value = $pinned ? date('Y-m-d H:i:s') : null;
        $this->db->execute(
            'UPDATE user_to_dialogs SET pinned_at = :pinned_at WHERE dialog_id = :dialog_id AND user_id = :user_id',
            [':pinned_at' => $value, ':dialog_id' => $dialogId, ':user_id' => $userId]
        );

        return ['dialog_uid' => $dialogUid, 'pinned' => $pinned, 'pinned_at' => $value];
    }

    /** @return array<string,mixed> */
    public function setArchived(string $userUid, string $dialogUid, bool $archived): array
    {
        [$userId, $dialogId] = $this->membership($userUid, $dialogUid);
        $value = $archived ? date('Y-m-d H:i:s') : null;
        $this->db->execute(
            'UPDATE user_to_dialogs SET archived_at = :archived_at WHERE dialog_id = :dialog_id AND user_id = :user_id',
            [':archived_at' => $value, ':dialog_id' => $dialogId, ':user_id' => $userId]
        );

        return ['dialog_uid' => $dialogUid, 'archived' => $archived, 'archived_at' => $value];
    }

    /** @return array<string,mixed> */
    public function setMuted(string $userUid, string $dialogUid, ?int $seconds): array
    {
        [$userId, $dialogId] = $this->membership($userUid, $dialogUid);

        if ($seconds !== null && ($seconds < 60 || $seconds > 31_536_000)) {
            throw new InvalidArgumentException('Некорректный период отключения уведомлений');
        }

        $value = $seconds === null ? null : date('Y-m-d H:i:s', time() + $seconds);
        $this->db->execute(
            'UPDATE user_to_dialogs SET muted_until = :muted_until WHERE dialog_id = :dialog_id AND user_id = :user_id',
            [':muted_until' => $value, ':dialog_id' => $dialogId, ':user_id' => $userId]
        );

        return [
            'dialog_uid' => $dialogUid,
            'muted' => $value !== null,
            'muted_until' => $value,
        ];
    }

    /** @return array{0:int,1:int} */
    private function membership(string $userUid, string $dialogUid): array
    {
        $row = $this->db->fetchOne(
            'SELECT u.id AS user_id, d.id AS dialog_id
             FROM users u
             INNER JOIN user_to_dialogs utd ON utd.user_id = u.id AND utd.is_deleted = 0
             INNER JOIN dialogs d ON d.id = utd.dialog_id
             WHERE u.uid = :user_uid AND u.is_active = 1 AND d.uid = :dialog_uid
             LIMIT 1',
            [':user_uid' => $userUid, ':dialog_uid' => $dialogUid]
        );

        if (!$row) {
            throw new DomainException('Нет доступа к диалогу');
        }

        return [(int) $row['user_id'], (int) $row['dialog_id']];
    }
}
