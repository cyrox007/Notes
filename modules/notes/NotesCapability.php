<?php

declare(strict_types=1);

namespace Modules\Notes;

use App\Helpers\CryptMethods;
use App\Services\PermissionService;
use App\Services\RolePolicyService;
use Core\DatabaseManager;
use Core\ProfileContentProvider;
use Core\WorkspaceNoteCreator;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class NotesCapability implements ProfileContentProvider, WorkspaceNoteCreator
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

    /** @return array{uid:string,title:string} */
    public function createWorkspaceNote(int $userId, string $title, string $content): array
    {
        if ($userId <= 0) {
            throw new DomainException('Требуется авторизация', 401);
        }

        $db = $this->db();
        (new PermissionService($db))->requirePermission($userId, 'notes.use');

        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '') {
            $title = 'Без названия';
        }
        if (mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Название заметки слишком длинное');
        }
        if (strlen($content) > 60000) {
            throw new InvalidArgumentException('Текст заметки слишком большой');
        }

        $limit = (int) (new RolePolicyService($db))->effectiveValue($userId, 'notes', 'max_notes');
        if ($limit > 0) {
            $count = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM notes WHERE user_id = :user_id AND is_deleted = 0',
                [':user_id' => $userId]
            );
            if ($count >= $limit) {
                throw new DomainException('Достигнут лимит заметок для вашей роли', 403);
            }
        }

        $uid = bin2hex(random_bytes(16));
        try {
            $encrypted = CryptMethods::encrypt($content, $uid);
        } catch (Throwable $e) {
            error_log('Workspace note encryption failed: ' . $e->getMessage());
            throw new RuntimeException('Не удалось безопасно сохранить заметку');
        }

        $now = date('Y-m-d H:i:s');
        $db->execute(
            'INSERT INTO notes (
                uid,user_id,notename,content,content_type,is_encrypted,
                created_note,updated_note,is_deleted,deleted_at
             ) VALUES (
                :uid,:user_id,:notename,:content,"text",1,
                :created_note,:updated_note,0,NULL
             )',
            [
                ':uid' => $uid,
                ':user_id' => $userId,
                ':notename' => $title,
                ':content' => $encrypted,
                ':created_note' => $now,
                ':updated_note' => $now,
            ]
        );

        return ['uid' => $uid, 'title' => $title];
    }

    private function db(): DatabaseManager
    {
        return DatabaseManager::getInstance();
    }
}
