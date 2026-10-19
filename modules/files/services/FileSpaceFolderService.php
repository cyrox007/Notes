<?php

declare(strict_types=1);

namespace App\Services\Files;

use Core\MutationCommandStore;
use DomainException;
use InvalidArgumentException;
use PDO;
use Core\DatabaseManager;
use RuntimeException;
use Throwable;

/**
 * Создание общих папок и настройка прав через атомарные команды.
 * Существующее личное хранилище user_files не затрагивается.
 */
final class FileSpaceFolderService
{
    private readonly DatabaseManager $db;

    public function __construct(
        PDO|DatabaseManager $connection,
        private readonly FileSpaceAccessService $access,
        private readonly MutationCommandStore $commands = new MutationCommandStore()
    ) {
        $this->db = $connection instanceof DatabaseManager
            ? $connection : DatabaseManager::forConnection($connection);
    }

    private const int PAGE_SIZE = 40;
    private const int FETCH_SIZE = 80;
    private const int MAX_SCAN = 640;

    /** @return array<string,mixed> */
    public function browse(int $actorId, ?int $parentId = null, ?string $cursorToken = null): array
    {
        $space = $this->access->space('global');
        $folderRights = $this->access->decisions(
            $actorId, 'global', $parentId,
            ['view', 'list', 'upload', 'create_folder', 'manage_acl']
        );
        if (!$folderRights['view']['allowed'] || !$folderRights['list']['allowed']) {
            throw new DomainException('Объект не найден или недоступен', 404);
        }

        $parent = null;
        if ($parentId !== null) {
            $parent = $this->folder((int) $space['id'], $parentId);
        }
        $folderVersion = $parent === null ? (int) $space['version'] : (int) $parent['version'];
        $position = $this->parseCursor($cursorToken, $parentId, $folderVersion);
        $items = [];
        $scanned = 0;
        $next = null;
        $lastVisible = null;
        $exhausted = false;
        while ($scanned < self::MAX_SCAN && count($items) <= self::PAGE_SIZE) {
            $params = [':space_id' => (int) $space['id']];
            $sql = 'SELECT id,uid,name,node_type,created_by,version,inherit_acl,byte_size,mime_type
                    FROM file_space_nodes
                    WHERE space_id=:space_id AND is_deleted=0 AND '
                . ($parentId === null ? 'parent_id IS NULL' : 'parent_id=:parent_id');
            if ($parentId !== null) {
                $params[':parent_id'] = $parentId;
            }
            if ($position !== null) {
                $sql .= ' AND (name>:after_name OR (name=:same_name AND id>:after_id))';
                $params[':after_name'] = $position['name'];
                $params[':same_name'] = $position['name'];
                $params[':after_id'] = $position['id'];
            }
            $batchSize = min(self::FETCH_SIZE, self::MAX_SCAN - $scanned);
            $stmt = $this->db->prepare($sql . ' ORDER BY name ASC,id ASC LIMIT ' . $batchSize);
            $stmt->execute($params);
            $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($batch === []) {
                $exhausted = true;
                break;
            }
            $rights = $this->access->childrenRights(
                $actorId, $parentId, $batch,
                ['view','download','rename_own','rename_others','move_own','move_others',
                 'delete_own','delete_others','replace_own','replace_others']
            );
            foreach ($batch as $item) {
                $position = ['name' => (string) $item['name'], 'id' => (int) $item['id']];
                $scanned++;
                $itemRights = $rights[(int) $item['id']];
                if (!$itemRights['view']['allowed']) {
                    continue;
                }
                if (count($items) === self::PAGE_SIZE) {
                    $next = $this->encodeCursor($lastVisible, $parentId, $folderVersion);
                    break 2;
                }
            $own = $item['created_by'] !== null && (int) $item['created_by'] === $actorId;
            $renamePermission = $own ? 'rename_own' : 'rename_others';
            $movePermission = $own ? 'move_own' : 'move_others';
            $deletePermission = $own ? 'delete_own' : 'delete_others';
            $replacePermission = $own ? 'replace_own' : 'replace_others';
            $items[] = [
                'id' => (int) $item['id'],
                'uid' => $item['uid'],
                'name' => $item['name'],
                'type' => $item['node_type'],
                'version' => (int) $item['version'],
                'inherit_acl' => (int) $item['inherit_acl'] === 1,
                'byte_size' => (int) $item['byte_size'],
                'can_download' => (string) $item['node_type'] === 'file'
                    && $itemRights['download']['allowed'],
                'can_rename' => $itemRights[$renamePermission]['allowed'],
                'can_move' => $itemRights[$movePermission]['allowed'],
                'can_delete' => $itemRights[$deletePermission]['allowed'],
                'can_replace' => (string) $item['node_type'] === 'file'
                    && $itemRights[$replacePermission]['allowed'],
                'is_author' => $own,

            ];
                $lastVisible = $position;
            }
            if (count($batch) < $batchSize) {
                $exhausted = true;
                break;
            }
        }
        if ($next === null && !$exhausted && $position !== null) {
            // Продолжение требуется только при достижении лимита просмотра;
            // число недоступных объектов не раскрывается в ответе.
            $next = $this->encodeCursor($position, $parentId, $folderVersion);
        }

        return [
            'space' => $space,
            'parent' => $parent,
            'version' => $parent === null ? (int) $space['version'] : (int) $parent['version'],
            'items' => $items,
            'next_cursor' => $next,
            'page_size' => self::PAGE_SIZE,
            'can_upload' => $folderRights['upload']['allowed'],
            'can_create' => $folderRights['create_folder']['allowed'],
            'can_manage' => $folderRights['manage_acl']['allowed'],
        ];
    }

    /** @return array{name:string,id:int}|null */
    private function parseCursor(?string $token, ?int $parentId, int $version): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        if (strlen($token) > 1024 || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) {
            throw new InvalidArgumentException('Некорректный указатель страницы');
        }
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || ($data['v'] ?? null) !== $version
            || ($data['p'] ?? null) !== $parentId
            || !is_string($data['n'] ?? null)
            || !is_int($data['i'] ?? null) || $data['i'] < 1
            || mb_strlen($data['n']) > 255) {
            throw new DomainException('Каталог обновился. Начните просмотр с первой страницы.', 409);
        }
        return ['name' => $data['n'], 'id' => $data['i']];
    }

    /** @param array{name:string,id:int}|null $position */
    private function encodeCursor(?array $position, ?int $parentId, int $version): ?string
    {
        if ($position === null) {
            return null;
        }
        $encoded = json_encode([
            'n' => $position['name'], 'i' => $position['id'],
            'p' => $parentId, 'v' => $version,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($encoded), '+/', '-_'), '=');
    }

    /** @return list<array<string,mixed>> */
    public function rules(int $actorId, ?int $folderId = null): array
    {
        $space = $this->access->space('global');
        $this->access->require($actorId, 'global', $folderId, 'manage_acl');
        if ($folderId !== null) {
            $this->folder((int) $space['id'], $folderId);
        }
        $query = $this->db->prepare(
            'SELECT subject_type,subject_id,permission_key,effect
             FROM file_space_acl
             WHERE space_id=:space_id AND scope_key=:scope
             ORDER BY permission_key,subject_type,subject_id'
        );
        $query->execute([
            ':space_id' => (int) $space['id'],
            ':scope' => self::scope($folderId),
        ]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    public function createFolder(
        int $actorId,
        ?int $parentId,
        string $name,
        int $expectedVersion,
        string $commandId
    ): array {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 255
            || preg_match('~[/\\\\\x00-\x1F]~u', $name) === 1) {
            throw new InvalidArgumentException('Название папки должно содержать от 1 до 255 символов без разделителей пути');
        }
        self::version($expectedVersion);
        $hash = $this->commands->requestHash([
            'parent_id' => $parentId,
            'name' => $name,
            'expected_version' => $expectedVersion,
        ]);
        $this->begin();
        try {
            $space = $this->access->space('global');
            $target = $this->lockTarget((int) $space['id'], $parentId);
            $this->access->require($actorId, 'global', $parentId, 'create_folder');

            $receipt = $this->acquire(
                $actorId, $commandId, 'files.folder.create',
                $space['uid'], self::scope($parentId), $hash
            );
            if (!$receipt['owner']) {
                return $this->replay($receipt);
            }

            if ((int) $target['version'] !== $expectedVersion) {
                return $this->conflict($receipt, $expectedVersion, (int) $target['version']);
            }

            $exists = $this->db->prepare(
                'SELECT id FROM file_space_nodes WHERE space_id=:space_id
                 AND ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id=:parent_id')
                 . ' AND name=:name AND is_deleted=0 LIMIT 1'
            );
            $params = [':space_id' => (int) $space['id'], ':name' => $name];
            if ($parentId !== null) {
                $params[':parent_id'] = $parentId;
            }
            $exists->execute($params);
            if ($exists->fetchColumn() !== false) {
                throw new InvalidArgumentException('Объект с таким названием уже существует');
            }

            $uid = bin2hex(random_bytes(16));
            $insert = $this->db->prepare(
                'INSERT INTO file_space_nodes (uid,space_id,parent_id,node_type,name,created_by,updated_by)
                 VALUES (:uid,:space_id,:parent_id,"folder",:name,:actor,:editor)'
            );
            $insert->execute([
                ':uid' => $uid,
                ':space_id' => (int) $space['id'],
                ':parent_id' => $parentId,
                ':name' => $name,
                ':actor' => $actorId,
                ':editor' => $actorId,
            ]);
            $nodeId = (int) $this->db->lastInsertId();
            $version = $this->bumpVersion((int) $space['id'], $parentId, $expectedVersion);
            $this->audit((int) $space['id'], $nodeId, $actorId, 'folder.create',
                ['parent_id' => $parentId, 'name' => $name]);

            $result = [
                'success' => true,
                'folder_id' => $nodeId,
                'folder_uid' => $uid,
                'version' => $version,
                'replayed' => false,
            ];
            $this->finish($receipt, $result, $version, MutationCommandStore::STATUS_APPLIED);
            $this->db->endTransaction();
            return $result;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function setRule(
        int $actorId,
        ?int $folderId,
        string $subjectType,
        int $subjectId,
        string $permission,
        string $effect,
        int $expectedVersion,
        string $commandId,
        ?bool $inheritAcl = null
    ): array {
        self::version($expectedVersion);
        if (!in_array($permission, FileSpaceAccessService::PERMISSIONS, true)) {
            throw new InvalidArgumentException('Недопустимое файловое право');
        }
        if (!in_array($effect, ['allow', 'deny', 'remove'], true)) {
            throw new InvalidArgumentException('Недопустимое действие с правилом');
        }
        if (!in_array($subjectType, ['everyone', 'role', 'user', 'group', 'author'], true)) {
            throw new InvalidArgumentException('Неизвестный субъект политики доступа');
        }
        if (in_array($subjectType, ['everyone', 'author'], true) && $subjectId !== 0) {
            throw new InvalidArgumentException('Для общего правила идентификатор должен быть нулевым');
        }
        if (in_array($subjectType, ['role', 'user', 'group'], true) && $subjectId < 1) {
            throw new InvalidArgumentException('Требуется идентификатор роли или пользователя');
        }
        if ($folderId === null && $inheritAcl !== null) {
            throw new InvalidArgumentException('Корневое пространство не наследует права');
        }

        $hash = $this->commands->requestHash([
            'folder_id' => $folderId, 'subject_type' => $subjectType,
            'subject_id' => $subjectId, 'permission' => $permission,
            'effect' => $effect, 'inherit_acl' => $inheritAcl,
            'expected_version' => $expectedVersion,
        ]);
        $this->begin();
        try {
            $space = $this->access->space('global');
            $target = $this->lockTarget((int) $space['id'], $folderId);
            $this->access->require($actorId, 'global', $folderId, 'manage_acl');
            $this->assertSubject($subjectType, $subjectId);

            $receipt = $this->acquire(
                $actorId, $commandId, 'files.acl.set', $space['uid'], self::scope($folderId), $hash
            );
            if (!$receipt['owner']) {
                return $this->replay($receipt);
            }
            if ((int) $target['version'] !== $expectedVersion) {
                return $this->conflict($receipt, $expectedVersion, (int) $target['version']);
            }

            $parameters = [
                ':space_id' => (int) $space['id'],
                ':scope' => self::scope($folderId),
                ':subject_type' => $subjectType,
                ':subject_id' => $subjectId,
                ':permission' => $permission,
            ];
            if ($effect === 'remove') {
                $statement = $this->db->prepare(
                    'DELETE FROM file_space_acl
                     WHERE space_id=:space_id AND scope_key=:scope
                     AND subject_type=:subject_type AND subject_id=:subject_id
                     AND permission_key=:permission'
                );
            } else {
                $statement = $this->db->prepare(
                    'INSERT INTO file_space_acl
                       (space_id,scope_key,subject_type,subject_id,permission_key,effect,updated_by)
                     VALUES (:space_id,:scope,:subject_type,:subject_id,:permission,:effect,:editor)
                     ON DUPLICATE KEY UPDATE effect=VALUES(effect),updated_by=VALUES(updated_by)'
                );
                $parameters[':effect'] = $effect;
                $parameters[':editor'] = $actorId;
            }
            $statement->execute($parameters);
            if ($folderId !== null && $inheritAcl !== null) {
                $inherit = $this->db->prepare(
                    'UPDATE file_space_nodes SET inherit_acl=:inherit
                     WHERE id=:id AND space_id=:space_id'
                );
                $inherit->execute([
                    ':inherit' => $inheritAcl ? 1 : 0,
                    ':id' => $folderId,
                    ':space_id' => (int) $space['id'],
                ]);
            }

            $version = $this->bumpVersion((int) $space['id'], $folderId, $expectedVersion);
            $this->audit((int) $space['id'], $folderId, $actorId, 'acl.set', [
                'scope' => self::scope($folderId),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'permission' => $permission,
                'effect' => $effect,
                'inherit_acl' => $inheritAcl,
            ]);
            $result = [
                'success' => true,
                'version' => $version,
                'replayed' => false,
                'scope' => self::scope($folderId),
            ];
            $this->finish($receipt, $result, $version, MutationCommandStore::STATUS_APPLIED);
            $this->db->endTransaction();
            return $result;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    /**
     * Одно назначение нескольким участникам и нескольким разрешениям.
     * Либо сохраняются все правила, либо не сохраняется ни одно.
     *
     * @param list<int> $subjectIds
     * @param list<string> $permissions
     * @return array<string,mixed>
     */
    public function setRulesBulk(
        int $actorId, ?int $folderId, string $subjectType,
        array $subjectIds, array $permissions, string $effect,
        int $expectedVersion, string $commandId
    ): array {
        self::version($expectedVersion);
        if (!in_array($subjectType, ['user','role','group'], true)
            || !in_array($effect, ['allow','deny','remove'], true)) {
            throw new InvalidArgumentException('Некорректная массовая команда изменения прав');
        }
        foreach ($subjectIds as $id) {
            if (!is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Некорректный получатель файловых прав');
            }
        }
        foreach ($permissions as $permission) {
            if (!is_string($permission)
                || !in_array($permission, FileSpaceAccessService::PERMISSIONS, true)) {
                throw new InvalidArgumentException('Недопустимое право общего каталога');
            }
        }
        $subjectIds = array_values(array_unique($subjectIds));
        $permissions = array_values(array_unique($permissions));
        sort($subjectIds, SORT_NUMERIC);
        sort($permissions, SORT_STRING);
        if ($subjectIds === [] || $permissions === []
            || count($subjectIds) > 25 || count($permissions) > 12
            || count($subjectIds) * count($permissions) > 100) {
            throw new InvalidArgumentException('Выберите от 1 до 100 правил, не более 25 получателей');
        }
        $hash = $this->commands->requestHash([
            'folder_id' => $folderId,
            'subject_type' => $subjectType,
            'subject_ids' => $subjectIds,
            'permissions' => $permissions,
            'effect' => $effect,
            'expected_version' => $expectedVersion,
        ]);
        $this->begin();
        try {
            $space = $this->access->space('global');
            $target = $this->lockTarget((int) $space['id'], $folderId);
            $this->access->require($actorId, 'global', $folderId, 'manage_acl');

            $receipt = $this->acquire(
                $actorId, $commandId, 'files.acl.bulk',
                (string) $space['uid'], self::scope($folderId), $hash
            );
            if (!$receipt['owner']) {
                return $this->replay($receipt);
            }
            if ((int) $target['version'] !== $expectedVersion) {
                return $this->conflict($receipt, $expectedVersion, (int) $target['version']);
            }
            foreach ($subjectIds as $id) {
                $this->assertSubject($subjectType, $id);
            }
            $statement = $this->db->prepare($effect === 'remove'
                ? 'DELETE FROM file_space_acl
                   WHERE space_id=:space AND scope_key=:scope
                     AND subject_type=:type AND subject_id=:subject_id
                     AND permission_key=:permission'
                : 'INSERT INTO file_space_acl
                   (space_id,scope_key,subject_type,subject_id,permission_key,effect,updated_by)
                   VALUES (:space,:scope,:type,:subject_id,:permission,:effect,:actor)
                   ON DUPLICATE KEY UPDATE effect=VALUES(effect),updated_by=VALUES(updated_by)');
            foreach ($subjectIds as $id) {
                foreach ($permissions as $permission) {
                    $parameters = [
                        ':space' => (int) $space['id'],
                        ':scope' => self::scope($folderId),
                        ':type' => $subjectType,
                        ':subject_id' => $id,
                        ':permission' => $permission,
                    ];
                    if ($effect !== 'remove') {
                        $parameters[':effect'] = $effect;
                        $parameters[':actor'] = $actorId;
                    }
                    $statement->execute($parameters);
                }
            }
            $version = $this->bumpVersion((int) $space['id'], $folderId, $expectedVersion);
            $this->audit((int) $space['id'], $folderId, $actorId, 'acl.bulk', [
                'scope' => self::scope($folderId),
                'subject_type' => $subjectType,
                'subject_ids' => $subjectIds,
                'permissions' => $permissions,
                'effect' => $effect,
            ]);
            $result = [
                'success' => true,
                'version' => $version,
                'rules_count' => count($subjectIds) * count($permissions),
                'scope' => self::scope($folderId),
                'replayed' => false,
            ];
            $this->finish($receipt, $result, $version, MutationCommandStore::STATUS_APPLIED);
            $this->db->endTransaction();
            return $result;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function folder(int $spaceId, int $folderId): array
    {
        $node = $this->access->node($spaceId, $folderId);
        if ($node['node_type'] !== 'folder') {
            throw new DomainException('Выбранный объект не является папкой', 404);
        }
        return $node;
    }

    /** @return array<string,mixed> */
    private function lockTarget(int $spaceId, ?int $folderId): array
    {
        if ($folderId === null) {
            $query = $this->db->prepare(
                'SELECT version FROM file_spaces WHERE id=:id AND is_archived=0 FOR UPDATE'
            );
            $query->execute([':id' => $spaceId]);
        } else {
            $this->folder($spaceId, $folderId);
            $query = $this->db->prepare(
                'SELECT version FROM file_space_nodes WHERE id=:id
                 AND space_id=:space_id AND node_type="folder" AND is_deleted=0 FOR UPDATE'
            );
            $query->execute([':id' => $folderId, ':space_id' => $spaceId]);
        }
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Папка недоступна', 404);
        }
        return $row;
    }

    private function bumpVersion(int $spaceId, ?int $folderId, int $expectedVersion): int
    {
        $params = [':id' => $folderId ?? $spaceId, ':version' => $expectedVersion];
        $statement = $this->db->prepare($folderId === null
            ? 'UPDATE file_spaces SET version=version+1 WHERE id=:id AND version=:version'
            : 'UPDATE file_space_nodes SET version=version+1 WHERE id=:id AND version=:version');
        $statement->execute($params);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Версия файлового каталога изменилась параллельно');
        }
        return $expectedVersion + 1;
    }

    private function assertSubject(string $type, int $id): void
    {
        if (!in_array($type, ['role', 'user', 'group'], true)) {
            return;
        }
        $statement = $this->db->prepare(
            match ($type) {
                'role' => 'SELECT id FROM roles WHERE id=:id',
                'group' => 'SELECT id FROM file_access_groups WHERE id=:id',
                default => 'SELECT id FROM users WHERE id=:id',
            }
        );
        $statement->execute([':id' => $id]);
        if ($statement->fetchColumn() === false) {
            throw new InvalidArgumentException('Указанная роль или учётная запись не найдена');
        }
    }

    private function audit(int $spaceId, ?int $folderId, int $actorId, string $action, array $details): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO file_space_events (space_id,node_id,actor_user_id,event_code,details_json)
             VALUES (:space_id,:node_id,:actor,:event,:details)'
        );
        $stmt->execute([
            ':space_id' => $spaceId,
            ':node_id' => $folderId,
            ':actor' => $actorId,
            ':event' => $action,
            ':details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    private function acquire(
        int $actorId, string $commandId, string $action,
        string $spaceUid, string $scope, string $hash
    ): array {
        return $this->commands->acquire(
            $this->db, 'user:' . $actorId, $commandId,
            'files', $action, 'file_space', $spaceUid . ':' . $scope, $hash
        );
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function replay(array $receipt): array
    {
        $result = $this->commands->storedResponse($receipt['record']);
        $this->db->endTransaction();
        return $result;
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function conflict(array $receipt, int $expected, int $current): array
    {
        $result = [
            'success' => false,
            'error' => 'version_conflict',
            'message' => 'Каталог или его права были изменены. Обновите страницу.',
            'expected_version' => $expected,
            'current_version' => $current,
        ];
        $this->finish($receipt, $result, $current, MutationCommandStore::STATUS_CONFLICT);
        $this->db->endTransaction();
        return $result;
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $result */
    private function finish(array $receipt, array $result, int $version, string $status): void
    {
        $this->commands->complete(
            $this->db, (int) $receipt['record']['id'], $receipt['token'], $status, $result, $version
        );
    }

    private static function scope(?int $folderId): string
    {
        return $folderId === null ? 'space' : 'node:' . $folderId;
    }

    private static function version(int $version): void
    {
        if ($version < 1) {
            throw new InvalidArgumentException('expected_version должен быть положительным');
        }
    }

    private function begin(): void
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('FileSpace требует отдельную транзакцию');
        }
        $this->db->beginTransaction();
    }

    private function rollback(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->endTransaction(false);
        }
    }
}
