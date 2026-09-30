<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class FileShareService
{
    public function __construct(private ?DatabaseManager $database = null)
    {
    }

    /** @return array<string,mixed> */
    public function create(int $userId, string $uid, ?string $expiresAt = null): array
    {
        $this->requireSharePermission($userId);
        $item = $this->ownedItem($userId, $uid);
        $expiresAt = $this->normalizeExpiry($expiresAt);

        if ((string) $item['type'] !== 'folder') {
            $path = $this->resolveStoredPath((string) ($item['path'] ?? ''));
            if ($path === null || !is_file($path) || !is_readable($path)) {
                throw new RuntimeException('Файл отсутствует в защищённом хранилище');
            }
        }

        if ($expiresAt === null) {
            $existing = $this->db()->fetchOne(
                'SELECT id,share_token,expires_at,created_at FROM file_shares '
                . 'WHERE file_id = :file_id AND owner_user_id = :owner_user_id '
                . 'AND is_active = 1 AND expires_at IS NULL ORDER BY id DESC LIMIT 1',
                [':file_id' => (int) $item['id'], ':owner_user_id' => $userId]
            );
            if ($existing) {
                return $this->sharePayload($existing, $item);
            }
        }

        $token = bin2hex(random_bytes(32));
        $createdAt = date('Y-m-d H:i:s');
        $this->db()->execute(
            'INSERT INTO file_shares (file_id,owner_user_id,share_token,expires_at,created_at,is_active) '
            . 'VALUES (:file_id,:owner_user_id,:share_token,:expires_at,:created_at,1)',
            [
                ':file_id' => (int) $item['id'],
                ':owner_user_id' => $userId,
                ':share_token' => $token,
                ':expires_at' => $expiresAt,
                ':created_at' => $createdAt,
            ]
        );

        $row = $this->db()->fetchOne(
            'SELECT id,share_token,expires_at,created_at FROM file_shares WHERE share_token = :token LIMIT 1',
            [':token' => $token]
        );
        if (!$row) {
            throw new RuntimeException('Не удалось сохранить публичную ссылку');
        }
        return $this->sharePayload($row, $item);
    }

    /** @return list<array<string,mixed>> */
    public function listOwnerShares(int $userId): array
    {
        $this->requireFilesUse($userId);
        $rows = $this->db()->fetchAll(
            'SELECT fs.id,fs.share_token,fs.expires_at,fs.created_at,fs.is_active,'
            . 'uf.uid,uf.name,uf.extension,uf.type,uf.size,uf.is_deleted '
            . 'FROM file_shares fs INNER JOIN user_files uf ON uf.id = fs.file_id '
            . 'WHERE fs.owner_user_id = :owner_user_id '
            . 'ORDER BY fs.created_at DESC, fs.id DESC',
            [':owner_user_id' => $userId]
        );
        $now = time();
        foreach ($rows as &$row) {
            $expired = !empty($row['expires_at']) && strtotime((string) $row['expires_at']) < $now;
            $row['status'] = (int) $row['is_deleted'] === 1
                ? 'missing'
                : ((int) $row['is_active'] !== 1 ? 'revoked' : ($expired ? 'expired' : 'active'));
        }
        unset($row);
        return $rows;
    }

    public function revokeById(int $userId, int $shareId): void
    {
        $this->requireFilesUse($userId);
        if ($shareId <= 0) {
            throw new InvalidArgumentException('Некорректная ссылка');
        }
        $exists = $this->db()->fetchValue(
            'SELECT id FROM file_shares WHERE id = :id AND owner_user_id = :owner_user_id LIMIT 1',
            [':id' => $shareId, ':owner_user_id' => $userId]
        );
        if ($exists === null) {
            throw new DomainException('Ссылка не найдена', 404);
        }
        $this->db()->execute(
            'UPDATE file_shares SET is_active = 0 WHERE id = :id AND owner_user_id = :owner_user_id',
            [':id' => $shareId, ':owner_user_id' => $userId]
        );
    }

    public function revokeByUid(int $userId, string $uid): void
    {
        $this->requireFilesUse($userId);
        $item = $this->ownedItem($userId, $uid);
        $this->db()->execute(
            'UPDATE file_shares SET is_active = 0 WHERE file_id = :file_id AND owner_user_id = :owner_user_id AND is_active = 1',
            [':file_id' => (int) $item['id'], ':owner_user_id' => $userId]
        );
    }

    public function updateExpiry(int $userId, int $shareId, ?string $expiresAt): void
    {
        $this->requireSharePermission($userId);
        $expiresAt = $this->normalizeExpiry($expiresAt);
        $exists = $this->db()->fetchValue(
            'SELECT id FROM file_shares WHERE id = :id AND owner_user_id = :owner_user_id LIMIT 1',
            [':id' => $shareId, ':owner_user_id' => $userId]
        );
        if ($exists === null) {
            throw new DomainException('Ссылка не найдена', 404);
        }
        $this->db()->execute(
            'UPDATE file_shares SET expires_at = :expires_at WHERE id = :id AND owner_user_id = :owner_user_id',
            [':expires_at' => $expiresAt, ':id' => $shareId, ':owner_user_id' => $userId]
        );
    }

    /** @return array<string,mixed> */
    public function resolveRoot(string $token): array
    {
        $token = $this->normalizeToken($token);
        $row = $this->db()->fetchOne(
            'SELECT fs.id AS share_id,fs.share_token,fs.expires_at,fs.created_at,'
            . 'uf.id,uf.uid,uf.user_id,uf.parent_id,uf.name,uf.extension,uf.type,uf.mime_type,uf.size,uf.path '
            . 'FROM file_shares fs INNER JOIN user_files uf ON uf.id = fs.file_id '
            . 'WHERE fs.share_token = :token AND fs.is_active = 1 '
            . 'AND (fs.expires_at IS NULL OR fs.expires_at >= :now) AND uf.is_deleted = 0 LIMIT 1',
            [':token' => $token, ':now' => date('Y-m-d H:i:s')]
        );
        if (!$row) {
            throw new DomainException('Ссылка недействительна или истекла', 404);
        }
        if ((string) $row['type'] !== 'folder') {
            $path = $this->resolveStoredPath((string) ($row['path'] ?? ''));
            if ($path === null || !is_file($path) || !is_readable($path)) {
                throw new DomainException('Файл недоступен', 404);
            }
            $row['path'] = $path;
        }
        return $row;
    }

    /** @return array{root:array<string,mixed>,current:array<string,mixed>,items:list<array<string,mixed>>,breadcrumb:list<array<string,mixed>>} */
    public function folderView(string $token, ?string $folderUid = null): array
    {
        $root = $this->resolveRoot($token);
        if ((string) $root['type'] !== 'folder') {
            throw new DomainException('Ссылка ведёт на файл, а не на папку', 404);
        }

        $current = $root;
        if ($folderUid !== null && trim($folderUid) !== '' && !hash_equals((string) $root['uid'], trim($folderUid))) {
            $candidate = $this->itemByUid((int) $root['user_id'], trim($folderUid));
            if ((string) $candidate['type'] !== 'folder' || !$this->isDescendant((int) $root['id'], $candidate)) {
                throw new DomainException('Папка недоступна по этой ссылке', 404);
            }
            $current = $candidate;
        }

        $items = $this->db()->fetchAll(
            'SELECT id,uid,parent_id,name,extension,type,mime_type,size,updated_at '
            . 'FROM user_files WHERE user_id = :user_id AND parent_id = :parent_id AND is_deleted = 0 '
            . 'ORDER BY type DESC,name ASC',
            [':user_id' => (int) $root['user_id'], ':parent_id' => (int) $current['id']]
        );

        return [
            'root' => $root,
            'current' => $current,
            'items' => $items,
            'breadcrumb' => $this->sharedBreadcrumb((int) $root['id'], $current),
        ];
    }

    /** @return array<string,mixed> */
    public function sharedFile(string $token, string $uid): array
    {
        $root = $this->resolveRoot($token);
        if ((string) $root['type'] !== 'folder') {
            if (!hash_equals((string) $root['uid'], trim($uid))) {
                throw new DomainException('Файл недоступен по этой ссылке', 404);
            }
            return $root;
        }

        $item = $this->itemByUid((int) $root['user_id'], trim($uid));
        if ((string) $item['type'] === 'folder' || !$this->isDescendant((int) $root['id'], $item)) {
            throw new DomainException('Файл недоступен по этой ссылке', 404);
        }
        $path = $this->resolveStoredPath((string) ($item['path'] ?? ''));
        if ($path === null || !is_file($path) || !is_readable($path)) {
            throw new DomainException('Файл недоступен', 404);
        }
        $item['path'] = $path;
        return $item;
    }

    /** @return array<string,mixed> */
    private function ownedItem(int $userId, string $uid): array
    {
        $this->requireFilesUse($userId);
        $uid = trim($uid);
        if ($uid === '' || strlen($uid) > 64) {
            throw new InvalidArgumentException('Некорректный идентификатор файла или папки');
        }
        $row = $this->db()->fetchOne(
            'SELECT id,uid,user_id,parent_id,name,extension,type,mime_type,size,path FROM user_files '
            . 'WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0 LIMIT 1',
            [':uid' => $uid, ':user_id' => $userId]
        );
        if (!$row) {
            throw new DomainException('Файл или папка не найдены', 404);
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function itemByUid(int $ownerId, string $uid): array
    {
        if ($uid === '' || strlen($uid) > 64) {
            throw new InvalidArgumentException('Некорректный идентификатор');
        }
        $row = $this->db()->fetchOne(
            'SELECT id,uid,user_id,parent_id,name,extension,type,mime_type,size,path,updated_at FROM user_files '
            . 'WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0 LIMIT 1',
            [':uid' => $uid, ':user_id' => $ownerId]
        );
        if (!$row) {
            throw new DomainException('Объект не найден', 404);
        }
        return $row;
    }

    /** @param array<string,mixed> $item */
    private function isDescendant(int $rootId, array $item): bool
    {
        if ((int) $item['id'] === $rootId) {
            return true;
        }
        $parentId = (int) ($item['parent_id'] ?? 0);
        $visited = [];
        for ($depth = 0; $depth < 100 && $parentId > 0; $depth++) {
            if ($parentId === $rootId) {
                return true;
            }
            if (isset($visited[$parentId])) {
                return false;
            }
            $visited[$parentId] = true;
            $row = $this->db()->fetchOne(
                'SELECT id,parent_id FROM user_files WHERE id = :id AND user_id = :user_id AND is_deleted = 0 LIMIT 1',
                [':id' => $parentId, ':user_id' => (int) $item['user_id']]
            );
            if (!$row) {
                return false;
            }
            $parentId = (int) ($row['parent_id'] ?? 0);
        }
        return false;
    }

    /** @param array<string,mixed> $current @return list<array<string,mixed>> */
    private function sharedBreadcrumb(int $rootId, array $current): array
    {
        $trail = [];
        $node = $current;
        $visited = [];
        for ($depth = 0; $depth < 100; $depth++) {
            $id = (int) $node['id'];
            $trail[] = ['uid' => (string) $node['uid'], 'name' => (string) $node['name'], 'id' => $id];
            if ($id === $rootId) {
                return array_reverse($trail);
            }
            $parentId = (int) ($node['parent_id'] ?? 0);
            if ($parentId <= 0 || isset($visited[$parentId])) {
                break;
            }
            $visited[$parentId] = true;
            $parent = $this->db()->fetchOne(
                'SELECT id,uid,user_id,parent_id,name FROM user_files '
                . 'WHERE id = :id AND user_id = :user_id AND is_deleted = 0 LIMIT 1',
                [':id' => $parentId, ':user_id' => (int) $current['user_id']]
            );
            if (!$parent) {
                break;
            }
            $node = $parent;
        }
        throw new DomainException('Папка больше не входит в опубликованное дерево', 404);
    }

    private function requireSharePermission(int $userId): void
    {
        $this->requireFilesUse($userId);
        if (!(bool) (new RolePolicyService($this->db()))->effectiveValue($userId, 'files', 'can_share')) {
            throw new DomainException('Создание публичных ссылок запрещено политикой роли', 403);
        }
    }

    private function requireFilesUse(int $userId): void
    {
        if ($userId <= 0) {
            throw new DomainException('Требуется авторизация', 401);
        }
        (new PermissionService($this->db()))->requirePermission($userId, 'files.use');
    }

    private function normalizeExpiry(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false || $timestamp <= time()) {
            throw new InvalidArgumentException('Срок действия ссылки должен быть в будущем');
        }
        if ($timestamp > time() + 366 * 86400) {
            throw new InvalidArgumentException('Срок действия ссылки не может превышать один год');
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function normalizeToken(string $token): string
    {
        $token = strtolower(trim($token));
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Некорректная ссылка');
        }
        return $token;
    }

    /** @param array<string,mixed> $share @param array<string,mixed> $item @return array<string,mixed> */
    private function sharePayload(array $share, array $item): array
    {
        return [
            'id' => (int) $share['id'],
            'token' => (string) $share['share_token'],
            'expires_at' => $share['expires_at'] !== null ? (string) $share['expires_at'] : null,
            'created_at' => (string) ($share['created_at'] ?? ''),
            'item' => [
                'uid' => (string) $item['uid'],
                'name' => (string) $item['name'],
                'extension' => (string) ($item['extension'] ?? ''),
                'type' => (string) ($item['type'] ?? 'file'),
                'mime_type' => (string) ($item['mime_type'] ?? 'application/octet-stream'),
                'size' => max(0, (int) ($item['size'] ?? 0)),
            ],
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
        return null;
    }

    private function db(): DatabaseManager
    {
        return $this->database ??= DatabaseManager::getInstance();
    }
}
