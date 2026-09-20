<?php

declare(strict_types=1);

namespace Modules\Files;

use App\Services\PermissionService;
use App\Services\RolePolicyService;
use App\Services\StorageQuotaService;
use Core\DatabaseManager;
use Core\ProfileContentProvider;
use Core\WorkspaceFileProvider;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class FilesCapability implements ProfileContentProvider, WorkspaceFileProvider
{
    public function moduleId(): string
    {
        return 'files';
    }

    /** @return list<array<string,mixed>> */
    public function ownerProfileItems(int $userId, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db()->fetchAll(
            "SELECT uid, name, extension, type, size, is_profile_public, updated_at
             FROM user_files
             WHERE user_id = :user_id
               AND is_deleted = 0
               AND type <> 'folder'
               AND uid IS NOT NULL
             ORDER BY updated_at DESC
             LIMIT " . $limit,
            [':user_id' => $userId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function publicProfileItems(int $userId, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db()->fetchAll(
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
        );
    }

    public function setProfileVisibility(int $userId, string $uid, bool $isPublic): void
    {
        $uid = trim($uid);
        if ($userId <= 0 || $uid === '' || strlen($uid) > 64) {
            throw new InvalidArgumentException('Некорректный объект публикации');
        }

        $id = $this->db()->fetchValue(
            "SELECT id FROM user_files
             WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0
               AND type <> 'folder' AND uid IS NOT NULL
             LIMIT 1",
            [':uid' => $uid, ':user_id' => $userId]
        );
        if ($id === null) {
            throw new InvalidArgumentException('Объект не найден или недоступен');
        }

        $this->db()->execute(
            'UPDATE user_files SET is_profile_public = :is_public WHERE id = :id AND user_id = :user_id AND is_deleted = 0',
            [':is_public' => $isPublic ? 1 : 0, ':id' => (int) $id, ':user_id' => $userId]
        );
    }

    /** @return array<string,mixed> */
    public function profileMetrics(int $userId): array
    {
        $db = $this->db();
        $filesCount = max(0, (int) $db->fetchValue(
            "SELECT COUNT(*) FROM user_files WHERE user_id = :user_id AND is_deleted = 0 AND type <> 'folder'",
            [':user_id' => $userId]
        ));

        $quota = new StorageQuotaService($db);
        try {
            $storage = $quota->usage($userId);
        } catch (Throwable $e) {
            error_log('Files capability storage metric fallback: ' . $e->getMessage());
            $used = $quota->usedBytes($userId);
            $limit = StorageQuotaService::DEFAULT_QUOTA_BYTES;
            $storage = [
                'used_bytes' => $used,
                'quota_bytes' => $limit,
                'remaining_bytes' => max(0, $limit - $used),
                'percent' => $limit > 0 ? round(min(100, ($used / $limit) * 100), 2) : 100.0,
            ];
        }

        return [
            'files_count' => $filesCount,
            'storage' => $storage,
        ];
    }

    /** @return list<array{uid:string,name:string,extension:string,type:string,mime_type:string,size:int,updated_at:string}> */
    public function listWorkspaceFiles(int $userId, string $query = '', int $limit = 50): array
    {
        $this->requireFilesUse($userId);
        $query = trim($query);
        if (mb_strlen($query) > 190) {
            throw new InvalidArgumentException('Слишком длинный поисковый запрос');
        }
        $limit = max(1, min(100, $limit));
        $params = [':user_id' => $userId];
        $where = "user_id = :user_id AND is_deleted = 0 AND type <> 'folder' AND uid IS NOT NULL";
        if ($query !== '') {
            $where .= ' AND (name LIKE :query OR extension LIKE :query)';
            $params[':query'] = '%' . $query . '%';
        }

        $rows = $this->db()->fetchAll(
            'SELECT uid,name,extension,type,mime_type,size,updated_at
             FROM user_files
             WHERE ' . $where . '
             ORDER BY updated_at DESC, id DESC
             LIMIT ' . $limit,
            $params
        );

        return array_values(array_map(static fn (array $row): array => [
            'uid' => (string) $row['uid'],
            'name' => (string) $row['name'],
            'extension' => (string) ($row['extension'] ?? ''),
            'type' => (string) ($row['type'] ?? 'file'),
            'mime_type' => (string) ($row['mime_type'] ?? 'application/octet-stream'),
            'size' => max(0, (int) ($row['size'] ?? 0)),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ], $rows));
    }

    /** @return array{uid:string,name:string,extension:string,type:string,mime_type:string,size:int,path:string} */
    public function exportWorkspaceFile(int $userId, string $uid): array
    {
        $this->requireFilesUse($userId);
        $file = $this->ownedFile($userId, $uid);
        $path = $this->resolveStoredPath((string) ($file['path'] ?? ''));
        if ($path === null || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Файл отсутствует в защищённом хранилище');
        }

        return [
            'uid' => (string) $file['uid'],
            'name' => (string) $file['name'],
            'extension' => (string) ($file['extension'] ?? ''),
            'type' => (string) ($file['type'] ?? 'file'),
            'mime_type' => (string) ($file['mime_type'] ?? 'application/octet-stream'),
            'size' => max(0, (int) ($file['size'] ?? filesize($path))),
            'path' => $path,
        ];
    }

    public function canShareWorkspaceFiles(int $userId): bool
    {
        try {
            $this->requireFilesUse($userId);
            return (bool) (new RolePolicyService($this->db()))->effectiveValue($userId, 'files', 'can_share');
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{token:string,expires_at:?string,file:array{uid:string,name:string,extension:string,mime_type:string,size:int}} */
    public function createWorkspaceFileShare(int $userId, string $uid, int $expiresHours = 0): array
    {
        $this->requireFilesUse($userId);
        if (!$this->canShareWorkspaceFiles($userId)) {
            throw new DomainException('Создание публичных ссылок запрещено политикой роли', 403);
        }
        if ($expiresHours < 0 || $expiresHours > 8760) {
            throw new InvalidArgumentException('Некорректный срок действия ссылки');
        }

        $file = $this->ownedFile($userId, $uid);
        $path = $this->resolveStoredPath((string) ($file['path'] ?? ''));
        if ($path === null || !is_file($path)) {
            throw new RuntimeException('Файл отсутствует в защищённом хранилище');
        }

        $db = $this->db();
        $expiresAt = $expiresHours > 0 ? date('Y-m-d H:i:s', time() + ($expiresHours * 3600)) : null;

        if ($expiresAt === null) {
            $existing = $db->fetchOne(
                'SELECT share_token,expires_at
                 FROM file_shares
                 WHERE file_id = :file_id
                   AND owner_user_id = :owner_user_id
                   AND is_active = 1
                   AND expires_at IS NULL
                 ORDER BY id DESC
                 LIMIT 1',
                [':file_id' => (int) $file['id'], ':owner_user_id' => $userId]
            );
            if ($existing) {
                return [
                    'token' => (string) $existing['share_token'],
                    'expires_at' => null,
                    'file' => $this->publicFile($file),
                ];
            }
        }

        $token = bin2hex(random_bytes(32));
        $db->execute(
            'INSERT INTO file_shares (file_id,owner_user_id,share_token,expires_at,created_at,is_active)
             VALUES (:file_id,:owner_user_id,:share_token,:expires_at,:created_at,1)',
            [
                ':file_id' => (int) $file['id'],
                ':owner_user_id' => $userId,
                ':share_token' => $token,
                ':expires_at' => $expiresAt,
                ':created_at' => date('Y-m-d H:i:s'),
            ]
        );

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'file' => $this->publicFile($file),
        ];
    }

    public function revokeWorkspaceFileShare(int $userId, string $uid): void
    {
        $this->requireFilesUse($userId);
        $file = $this->ownedFile($userId, $uid);
        $this->db()->execute(
            'UPDATE file_shares SET is_active = 0
             WHERE file_id = :file_id AND owner_user_id = :owner_user_id AND is_active = 1',
            [':file_id' => (int) $file['id'], ':owner_user_id' => $userId]
        );
    }

    /** @return array{token:string,expires_at:?string,file:array{uid:string,name:string,extension:string,type:string,mime_type:string,size:int,path:string}} */
    public function resolveWorkspaceFileShare(string $token): array
    {
        $token = strtolower(trim($token));
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Некорректная ссылка');
        }

        $row = $this->db()->fetchOne(
            "SELECT
                fs.share_token,fs.expires_at,
                uf.id,uf.uid,uf.user_id,uf.name,uf.extension,uf.type,uf.mime_type,uf.size,uf.path
             FROM file_shares fs
             INNER JOIN user_files uf ON uf.id = fs.file_id
             WHERE fs.share_token = :share_token
               AND fs.is_active = 1
               AND (fs.expires_at IS NULL OR fs.expires_at >= :now)
               AND uf.is_deleted = 0
               AND uf.type <> 'folder'
             LIMIT 1",
            [':share_token' => $token, ':now' => date('Y-m-d H:i:s')]
        );
        if (!$row) {
            throw new DomainException('Ссылка недействительна или истекла', 404);
        }

        $path = $this->resolveStoredPath((string) ($row['path'] ?? ''));
        if ($path === null || !is_file($path) || !is_readable($path)) {
            throw new DomainException('Файл недоступен', 404);
        }

        return [
            'token' => (string) $row['share_token'],
            'expires_at' => $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
            'file' => [
                'uid' => (string) $row['uid'],
                'name' => (string) $row['name'],
                'extension' => (string) ($row['extension'] ?? ''),
                'type' => (string) ($row['type'] ?? 'file'),
                'mime_type' => (string) ($row['mime_type'] ?? 'application/octet-stream'),
                'size' => max(0, (int) ($row['size'] ?? 0)),
                'path' => $path,
            ],
        ];
    }

    private function requireFilesUse(int $userId): void
    {
        if ($userId <= 0) {
            throw new DomainException('Требуется авторизация', 401);
        }
        (new PermissionService($this->db()))->requirePermission($userId, 'files.use');
    }

    /** @return array<string,mixed> */
    private function ownedFile(int $userId, string $uid): array
    {
        $uid = trim($uid);
        if ($uid === '' || strlen($uid) > 64) {
            throw new InvalidArgumentException('Некорректный идентификатор файла');
        }
        $row = $this->db()->fetchOne(
            "SELECT id,uid,user_id,name,extension,type,mime_type,size,path
             FROM user_files
             WHERE uid = :uid
               AND user_id = :user_id
               AND is_deleted = 0
               AND type <> 'folder'
             LIMIT 1",
            [':uid' => $uid, ':user_id' => $userId]
        );
        if (!$row) {
            throw new DomainException('Файл не найден или недоступен', 404);
        }
        return $row;
    }

    /** @param array<string,mixed> $file @return array{uid:string,name:string,extension:string,mime_type:string,size:int} */
    private function publicFile(array $file): array
    {
        return [
            'uid' => (string) $file['uid'],
            'name' => (string) $file['name'],
            'extension' => (string) ($file['extension'] ?? ''),
            'mime_type' => (string) ($file['mime_type'] ?? 'application/octet-stream'),
            'size' => max(0, (int) ($file['size'] ?? 0)),
        ];
    }

    private function resolveStoredPath(string $storedPath): ?string
    {
        if ($storedPath === '') {
            return null;
        }
        $absolute = str_starts_with($storedPath, '/')
            || str_starts_with($storedPath, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $storedPath) === 1;
        $candidate = $absolute
            ? $storedPath
            : rtrim(SITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($storedPath, '/\\');
        $realPath = realpath($candidate);
        if ($realPath === false) {
            return null;
        }

        $allowedRoots = [];
        $configured = getenv('PRIVATE_STORAGE_PATH');
        $private = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : dirname(SITEPATH) . DIRECTORY_SEPARATOR . 'notes-private-storage';
        $privateRoot = realpath($private);
        if ($privateRoot !== false) {
            $allowedRoots[] = rtrim($privateRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }
        $legacyRoot = realpath(rtrim(SITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'file_manager');
        if ($legacyRoot !== false) {
            $allowedRoots[] = rtrim($legacyRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }

        $candidatePrefix = rtrim($realPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($allowedRoots as $root) {
            if (str_starts_with($candidatePrefix, $root)) {
                return $realPath;
            }
        }
        error_log('Blocked Files capability path outside storage roots: ' . $storedPath);
        return null;
    }

    private function db(): DatabaseManager
    {
        return DatabaseManager::getInstance();
    }
}
