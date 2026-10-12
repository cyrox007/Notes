<?php

declare(strict_types=1);

namespace Modules\Notes;

use App\Services\PermissionService;
use Core\DatabaseManager;
use Core\WorkspaceCalendarLinkTargetProvider;
use DomainException;
use InvalidArgumentException;

final class NotesCalendarLinkProvider implements WorkspaceCalendarLinkTargetProvider
{
    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    public function listCalendarLinkTargets(int $userId, string $query = '', int $limit = 20): array
    {
        $this->requireAccess($userId);
        $query = $this->normalizeQuery($query);
        $limit = max(1, min(50, $limit));
        $params = [':user_id' => $userId];
        $where = 'user_id = :user_id AND is_deleted = 0';
        if ($query !== '') {
            $where .= ' AND notename LIKE :query';
            $params[':query'] = '%' . $query . '%';
        }

        $rows = $this->db->fetchAll(
            'SELECT uid,notename,updated_note FROM notes WHERE ' . $where
            . ' ORDER BY updated_note DESC, id DESC LIMIT ' . $limit,
            $params
        );
        return array_values(array_map(fn (array $row): array => $this->target($row), $rows));
    }

    public function resolveCalendarLinkTarget(int $userId, string $uid): array
    {
        $this->requireAccess($userId);
        $row = $this->db->fetchOne(
            'SELECT uid,notename,updated_note FROM notes '
            . 'WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0 LIMIT 1',
            [':uid' => $this->normalizeUid($uid), ':user_id' => $userId]
        );
        if (!is_array($row)) {
            throw new DomainException('Заметка не найдена или недоступна', 404);
        }
        return $this->target($row);
    }

    private function requireAccess(int $userId): void
    {
        if ($userId <= 0) {
            throw new DomainException('Требуется авторизация', 401);
        }
        (new PermissionService($this->db))->requirePermission($userId, 'notes.use');
    }

    private function normalizeQuery(string $query): string
    {
        $query = trim($query);
        if (mb_strlen($query) > 190) {
            throw new InvalidArgumentException('Слишком длинный поисковый запрос');
        }
        return $query;
    }

    private function normalizeUid(string $uid): string
    {
        $uid = trim($uid);
        if ($uid === '' || strlen($uid) > 64) {
            throw new InvalidArgumentException('Некорректный идентификатор заметки');
        }
        return $uid;
    }

    /** @param array<string,mixed> $row @return array{uid:string,title:string,subtitle:string,href:string} */
    private function target(array $row): array
    {
        $uid = (string) $row['uid'];
        $title = trim((string) ($row['notename'] ?? ''));
        return [
            'uid' => $uid,
            'title' => $title !== '' ? $title : 'Без названия',
            'subtitle' => 'Заметка',
            'href' => '/notes/' . rawurlencode($uid) . '/edit',
        ];
    }
}
