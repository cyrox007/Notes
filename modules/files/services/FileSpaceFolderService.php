<?php

declare(strict_types=1);

namespace App\Services\Files;

use Core\MutationCommandStore;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Создание общих папок и настройка прав через атомарные команды.
 * Существующее личное хранилище user_files не затрагивается.
 */
final class FileSpaceFolderService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FileSpaceAccessService $access,
        private readonly MutationCommandStore $commands = new MutationCommandStore()
    ) {
    }

    /** @return array<string,mixed> */
    public function browse(int $actorId, ?int $parentId = null): array
    {
        $space = $this->access->space('global');
        $this->access->require($actorId, 'global', $parentId, 'view');
        $this->access->require($actorId, 'global', $parentId, 'list');

        $parent = null;
        if ($parentId !== null) {
            $parent = $this->folder((int) $space['id'], $parentId);
        }
        $sql = 'SELECT id,uid,name,node_type,created_by,version,inherit_acl,byte_size,mime_type
                FROM file_space_nodes
                WHERE space_id=:space_id AND is_deleted=0 AND '
            . ($parentId === null ? 'parent_id IS NULL' : 'parent_id=:parent_id')
            . ' ORDER BY node_type DESC,name ASC,id ASC LIMIT 200';
        $query = $this->pdo->prepare($sql);
        $params = [':space_id' => (int) $space['id']];
        if ($parentId !== null) {
            $params[':parent_id'] = $parentId;
        }
        $query->execute($params);

        $items = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if (!$this->access->decision($actorId, 'global', (int) $item['id'], 'view')['allowed']) {
                continue;
            }
            $items[] = [
                'id' => (int) $item['id'],
                'uid' => $item['uid'],
                'name' => $item['name'],
                'type' => $item['node_type'],
                'version' => (int) $item['version'],
                'inherit_acl' => (int) $item['inherit_acl'] === 1,
                'byte_size' => (int) $item['byte_size'],
                'can_download' => (string) $item['node_type'] === 'file'
                    && $this->access->decision($actorId, 'global', (int) $item['id'], 'download')['allowed'],
            ];
        }

        return [
            'space' => $space,
            'parent' => $parent,
            'version' => $parent === null ? (int) $space['version'] : (int) $parent['version'],
            'items' => $items,
            'can_upload' => $this->access->decision($actorId, 'global', $parentId, 'upload')['allowed'],
            'can_create' => $this->access->decision($actorId, 'global', $parentId, 'create_folder')['allowed'],
            'can_manage' => $this->access->decision($actorId, 'global', $parentId, 'manage_acl')['allowed'],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function rules(int $actorId, ?int $folderId = null): array
    {
        $space = $this->access->space('global');
        $this->access->require($actorId, 'global', $folderId, 'manage_acl');
        if ($folderId !== null) {
            $this->folder((int) $space['id'], $folderId);
        }
        $query = $this->pdo->prepare(
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

            $exists = $this->pdo->prepare(
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
            $insert = $this->pdo->prepare(
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
            $nodeId = (int) $this->pdo->lastInsertId();
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
            $this->pdo->commit();
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
        if (!in_array($subjectType, ['everyone', 'role', 'user', 'author'], true)) {
            throw new InvalidArgumentException('Неизвестный субъект политики доступа');
        }
        if (in_array($subjectType, ['everyone', 'author'], true) && $subjectId !== 0) {
            throw new InvalidArgumentException('Для общего правила идентификатор должен быть нулевым');
        }
        if (in_array($subjectType, ['role', 'user'], true) && $subjectId < 1) {
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
                $statement = $this->pdo->prepare(
                    'DELETE FROM file_space_acl
                     WHERE space_id=:space_id AND scope_key=:scope
                     AND subject_type=:subject_type AND subject_id=:subject_id
                     AND permission_key=:permission'
                );
            } else {
                $statement = $this->pdo->prepare(
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
                $inherit = $this->pdo->prepare(
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
            $this->pdo->commit();
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
            $query = $this->pdo->prepare(
                'SELECT version FROM file_spaces WHERE id=:id AND is_archived=0 FOR UPDATE'
            );
            $query->execute([':id' => $spaceId]);
        } else {
            $this->folder($spaceId, $folderId);
            $query = $this->pdo->prepare(
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
        $statement = $this->pdo->prepare($folderId === null
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
        if (!in_array($type, ['role', 'user'], true)) {
            return;
        }
        $statement = $this->pdo->prepare(
            $type === 'role' ? 'SELECT id FROM roles WHERE id=:id' : 'SELECT id FROM users WHERE id=:id'
        );
        $statement->execute([':id' => $id]);
        if ($statement->fetchColumn() === false) {
            throw new InvalidArgumentException('Указанная роль или учётная запись не найдена');
        }
    }

    private function audit(int $spaceId, ?int $folderId, int $actorId, string $action, array $details): void
    {
        $stmt = $this->pdo->prepare(
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
            $this->pdo, 'user:' . $actorId, $commandId,
            'files', $action, 'file_space', $spaceUid . ':' . $scope, $hash
        );
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function replay(array $receipt): array
    {
        $result = $this->commands->storedResponse($receipt['record']);
        $this->pdo->commit();
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
        $this->pdo->commit();
        return $result;
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $result */
    private function finish(array $receipt, array $result, int $version, string $status): void
    {
        $this->commands->complete(
            $this->pdo, (int) $receipt['record']['id'], $receipt['token'], $status, $result, $version
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
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('FileSpace требует отдельную транзакцию');
        }
        $this->pdo->beginTransaction();
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
