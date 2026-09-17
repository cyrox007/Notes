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

    /** @return list<array<string,mixed>> */
    public function listStates(string $userUid): array
    {
        $rows = $this->db->fetchAll(
            'SELECT
                d.uid AS dialog_uid,
                utd.pinned_at,
                utd.archived_at,
                utd.muted_until
             FROM users u
             INNER JOIN user_to_dialogs utd ON utd.user_id = u.id AND utd.is_deleted = 0
             INNER JOIN dialogs d ON d.id = utd.dialog_id
             WHERE u.uid = :user_uid AND u.is_active = 1',
            [':user_uid' => $userUid]
        );

        $now = time();
        foreach ($rows as &$row) {
            $row['pinned'] = !empty($row['pinned_at']);
            $row['archived'] = !empty($row['archived_at']);
            $row['muted'] = !empty($row['muted_until']) && strtotime((string) $row['muted_until']) > $now;
        }
        unset($row);

        return $rows;
    }

    /** @return array<string,mixed> */
    public function togglePinned(string $userUid, string $dialogUid): array
    {
        $membership = $this->membership($userUid, $dialogUid);
        $pinned = empty($membership['pinned_at']);
        return $this->setPinnedByMembership($membership, $dialogUid, $pinned);
    }

    /** @return array<string,mixed> */
    public function toggleArchived(string $userUid, string $dialogUid): array
    {
        $membership = $this->membership($userUid, $dialogUid);
        $archived = empty($membership['archived_at']);
        return $this->setArchivedByMembership($membership, $dialogUid, $archived);
    }

    /** @return array<string,mixed> */
    public function toggleMuted(string $userUid, string $dialogUid, int $seconds = 3600): array
    {
        $membership = $this->membership($userUid, $dialogUid);
        $currentlyMuted = !empty($membership['muted_until']) && strtotime((string) $membership['muted_until']) > time();
        return $this->setMutedByMembership($membership, $dialogUid, $currentlyMuted ? null : $seconds);
    }

    /** @return array<string,mixed> */
    public function setPinned(string $userUid, string $dialogUid, bool $pinned): array
    {
        return $this->setPinnedByMembership($this->membership($userUid, $dialogUid), $dialogUid, $pinned);
    }

    /** @return array<string,mixed> */
    public function setArchived(string $userUid, string $dialogUid, bool $archived): array
    {
        return $this->setArchivedByMembership($this->membership($userUid, $dialogUid), $dialogUid, $archived);
    }

    /** @return array<string,mixed> */
    public function setMuted(string $userUid, string $dialogUid, ?int $seconds): array
    {
        return $this->setMutedByMembership($this->membership($userUid, $dialogUid), $dialogUid, $seconds);
    }

    private function setPinnedByMembership(array $membership, string $dialogUid, bool $pinned): array
    {
        $value = $pinned ? date('Y-m-d H:i:s') : null;
        $this->db->execute(
            'UPDATE user_to_dialogs SET pinned_at = :pinned_at WHERE dialog_id = :dialog_id AND user_id = :user_id',
            [
                ':pinned_at' => $value,
                ':dialog_id' => (int) $membership['dialog_id'],
                ':user_id' => (int) $membership['user_id'],
            ]
        );
        return ['dialog_uid' => $dialogUid, 'pinned' => $pinned, 'pinned_at' => $value];
    }

    private function setArchivedByMembership(array $membership, string $dialogUid, bool $archived): array
    {
        $value = $archived ? date('Y-m-d H:i:s') : null;
        $this->db->execute(
            'UPDATE user_to_dialogs SET archived_at = :archived_at WHERE dialog_id = :dialog_id AND user_id = :user_id',
            [
                ':archived_at' => $value,
                ':dialog_id' => (int) $membership['dialog_id'],
                ':user_id' => (int) $membership['user_id'],
            ]
        );
        return ['dialog_uid' => $dialogUid, 'archived' => $archived, 'archived_at' => $value];
    }

    private function setMutedByMembership(array $membership, string $dialogUid, ?int $seconds): array
    {
        if ($seconds !== null && ($seconds < 60 || $seconds > 31_536_000)) {
            throw new InvalidArgumentException('Некорректный период отключения уведомлений');
        }

        $value = $seconds === null ? null : date('Y-m-d H:i:s', time() + $seconds);
        $this->db->execute(
            'UPDATE user_to_dialogs SET muted_until = :muted_until WHERE dialog_id = :dialog_id AND user_id = :user_id',
            [
                ':muted_until' => $value,
                ':dialog_id' => (int) $membership['dialog_id'],
                ':user_id' => (int) $membership['user_id'],
            ]
        );
        return ['dialog_uid' => $dialogUid, 'muted' => $value !== null, 'muted_until' => $value];
    }

    /** @return array<string,mixed> */
    private function membership(string $userUid, string $dialogUid): array
    {
        $row = $this->db->fetchOne(
            'SELECT
                u.id AS user_id,
                d.id AS dialog_id,
                utd.pinned_at,
                utd.archived_at,
                utd.muted_until
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

        return $row;
    }
}
