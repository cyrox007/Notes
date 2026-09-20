<?php

declare(strict_types=1);

namespace Modules\Notes;

use Core\DatabaseManager;
use Core\ProfileContentProvider;
use InvalidArgumentException;

final class NotesCapability implements ProfileContentProvider
{
    public function moduleId(): string
    {
        return 'notes';
    }

    /** @return list<array<string,mixed>> */
    public function ownerProfileItems(int $userId, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db()->fetchAll(
            'SELECT uid, notename AS title, is_profile_public, updated_note AS updated_at
             FROM notes
             WHERE user_id = :user_id AND is_deleted = 0
             ORDER BY updated_note DESC
             LIMIT ' . $limit,
            [':user_id' => $userId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function publicProfileItems(int $userId, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db()->fetchAll(
            'SELECT uid, notename AS title, updated_note AS updated_at
             FROM notes
             WHERE user_id = :user_id AND is_deleted = 0 AND is_profile_public = 1
             ORDER BY updated_note DESC
             LIMIT ' . $limit,
            [':user_id' => $userId]
        );
    }

    public function setProfileVisibility(int $userId, string $uid, bool $isPublic): void
    {
        $uid = trim($uid);
        if ($userId <= 0 || $uid === '' || strlen($uid) > 64) {
            throw new InvalidArgumentException('Некорректный объект публикации');
        }

        $id = $this->db()->fetchValue(
            'SELECT id FROM notes WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0 LIMIT 1',
            [':uid' => $uid, ':user_id' => $userId]
        );
        if ($id === null) {
            throw new InvalidArgumentException('Объект не найден или недоступен');
        }

        $this->db()->execute(
            'UPDATE notes SET is_profile_public = :is_public WHERE id = :id AND user_id = :user_id AND is_deleted = 0',
            [':is_public' => $isPublic ? 1 : 0, ':id' => (int) $id, ':user_id' => $userId]
        );
    }

    /** @return array<string,mixed> */
    public function profileMetrics(int $userId): array
    {
        return [
            'notes_count' => max(0, (int) $this->db()->fetchValue(
                'SELECT COUNT(*) FROM notes WHERE user_id = :user_id AND is_deleted = 0',
                [':user_id' => $userId]
            )),
        ];
    }

    private function db(): DatabaseManager
    {
        return DatabaseManager::getInstance();
    }
}
