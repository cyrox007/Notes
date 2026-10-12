<?php

declare(strict_types=1);

namespace Modules\Files;

use App\Services\PermissionService;
use Core\DatabaseManager;
use Core\WorkspaceCalendarLinkTargetProvider;
use DomainException;
use InvalidArgumentException;

final class FilesCalendarLinkProvider implements WorkspaceCalendarLinkTargetProvider
{
    public function __construct(private ?DatabaseManager $db = null)
    {
    }

    private function database(): DatabaseManager
    {
        return $this->db ??= DatabaseManager::getInstance();
    }

    public function listCalendarLinkTargets(int $userId, string $query = '', int $limit = 20): array
    {
        $this->requireAccess($userId);
        $query = $this->normalizeQuery($query);
        $limit = max(1, min(50, $limit));
        $params = [':user_id' => $userId];
        $where = "user_id = :user_id AND is_deleted = 0 AND type <> 'folder' AND uid IS NOT NULL";
        if ($query !== '') {
            $where .= ' AND (name LIKE :query OR extension LIKE :query)';
            $params[':query'] = '%' . $query . '%';
        }

        $rows = $this->database()->fetchAll(
            'SELECT uid,name,extension,size FROM user_files WHERE ' . $where
            . ' ORDER BY updated_at DESC, id DESC LIMIT ' . $limit,
            $params
        );
        return array_values(array_map(fn (array $row): array => $this->target($row), $rows));
    }

    public function resolveCalendarLinkTarget(int $userId, string $uid): array
    {
        $this->requireAccess($userId);
        $row = $this->database()->fetchOne(
            "SELECT uid,name,extension,size FROM user_files "
            . "WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0 AND type <> 'folder' LIMIT 1",
            [':uid' => $this->normalizeUid($uid), ':user_id' => $userId]
        );
        if (!is_array($row)) {
            throw new DomainException('Файл не найден или недоступен', 404);
        }
        return $this->target($row);
    }

    private function requireAccess(int $userId): void
    {
        if ($userId <= 0) {
            throw new DomainException('Требуется авторизация', 401);
        }
        (new PermissionService($this->database()))->requirePermission($userId, 'files.use');
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
            throw new InvalidArgumentException('Некорректный идентификатор файла');
        }
        return $uid;
    }

    /** @param array<string,mixed> $row @return array{uid:string,title:string,subtitle:string,href:string} */
    private function target(array $row): array
    {
        $extension = strtoupper(trim((string) ($row['extension'] ?? '')));
        $size = max(0, (int) ($row['size'] ?? 0));
        $subtitle = trim(($extension !== '' ? $extension . ' · ' : '') . $this->formatBytes($size));
        return [
            'uid' => (string) $row['uid'],
            'title' => (string) $row['name'],
            'subtitle' => $subtitle !== '' ? $subtitle : 'Файл',
            'href' => '/files/',
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' Б';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' КБ';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' МБ';
        }
        return round($bytes / 1073741824, 1) . ' ГБ';
    }
}
