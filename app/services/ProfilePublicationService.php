<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use InvalidArgumentException;

final class ProfilePublicationService
{
    private const TYPES = [
        'note' => 'notes',
        'task' => 'tasks',
        'file' => 'user_files',
    ];

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /**
     * @return array{
     *   notes:list<array<string,mixed>>,
     *   tasks:list<array<string,mixed>>,
     *   files:list<array<string,mixed>>,
     *   metrics:array<string,mixed>
     * }
     */
    public function ownerItems(int $userId, int $limitPerType = 12): array
    {
        $limit = max(1, min(50, $limitPerType));

        return [
            'notes' => $this->db->fetchAll(
                'SELECT uid, notename AS title, is_profile_public, updated_note AS updated_at
                 FROM notes
                 WHERE user_id = :user_id AND is_deleted = 0
                 ORDER BY updated_note DESC
                 LIMIT ' . $limit,
                [':user_id' => $userId]
            ),
            'tasks' => $this->db->fetchAll(
                'SELECT uid, title, status, priority, due_date, is_profile_public, updated_at
                 FROM tasks
                 WHERE user_id = :user_id AND is_deleted = 0
                 ORDER BY updated_at DESC
                 LIMIT ' . $limit,
                [':user_id' => $userId]
            ),
            'files' => $this->db->fetchAll(
                "SELECT uid, name, extension, type, size, is_profile_public, updated_at
                 FROM user_files
                 WHERE user_id = :user_id
                   AND is_deleted = 0
                   AND type <> 'folder'
                   AND uid IS NOT NULL
                 ORDER BY updated_at DESC
                 LIMIT " . $limit,
                [':user_id' => $userId]
            ),
            'metrics' => (new ProfileMetricsService($this->db))->summary($userId),
        ];
    }

    /**
     * Returns safe public-profile metadata only. Note content, file paths, share
     * tokens and private user data are deliberately absent from every query.
     *
     * @return array{
     *   notes:list<array<string,mixed>>,
     *   tasks:list<array<string,mixed>>,
     *   files:list<array<string,mixed>>
     * }
     */
    public function publicItems(int $userId, int $limitPerType = 24): array
    {
        $limit = max(1, min(50, $limitPerType));

        return [
            'notes' => $this->db->fetchAll(
                'SELECT uid, notename AS title, updated_note AS updated_at
                 FROM notes
                 WHERE user_id = :user_id AND is_deleted = 0 AND is_profile_public = 1
                 ORDER BY updated_note DESC
                 LIMIT ' . $limit,
                [':user_id' => $userId]
            ),
            'tasks' => $this->db->fetchAll(
                'SELECT uid, title, status, priority, due_date, updated_at
                 FROM tasks
                 WHERE user_id = :user_id AND is_deleted = 0 AND is_profile_public = 1
                 ORDER BY updated_at DESC
                 LIMIT ' . $limit,
                [':user_id' => $userId]
            ),
            'files' => $this->db->fetchAll(
                "SELECT uid, name, extension, type, size, updated_at
                 FROM user_files
                 WHERE user_id = :user_id
                   AND is_deleted = 0
                   AND is_profile_public = 1
                   AND type <> 'folder'
                   AND uid IS NOT NULL
                 ORDER BY updated_at DESC
                 LIMIT " . $limit,
                [':user_id' => $userId]
            ),
        ];
    }

    public function setVisibility(int $userId, string $type, string $uid, bool $isPublic): void
    {
        $type = trim($type);
        $uid = trim($uid);
        if (!isset(self::TYPES[$type]) || $uid === '' || strlen($uid) > 64) {
            throw new InvalidArgumentException('Некорректный объект публикации');
        }

        $table = self::TYPES[$type];
        $extra = $type === 'file' ? " AND type <> 'folder' AND uid IS NOT NULL" : '';
        $owned = $this->db->fetchValue(
            'SELECT id FROM ' . $table . '
             WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0' . $extra . '
             LIMIT 1',
            [':uid' => $uid, ':user_id' => $userId]
        );
        if ($owned === null) {
            throw new InvalidArgumentException('Объект не найден или недоступен');
        }

        $this->db->execute(
            'UPDATE ' . $table . '
             SET is_profile_public = :is_public
             WHERE id = :id AND user_id = :user_id AND is_deleted = 0',
            [
                ':is_public' => $isPublic ? 1 : 0,
                ':id' => (int) $owned,
                ':user_id' => $userId,
            ]
        );
    }
}
