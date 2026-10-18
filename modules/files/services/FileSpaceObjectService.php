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
 * Версионные изменения общих объектов и корзина.
 * Удаление скрывает всё поддерево, сохраняя физические данные для восстановления.
 */
final class FileSpaceObjectService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FileSpaceAccessService $access,
        private readonly MutationCommandStore $commands = new MutationCommandStore()
    ) {
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function mutate(
        int $actorId,
        int $nodeId,
        string $action,
        int $expectedVersion,
        int $expectedParentVersion,
        string $commandId,
        array $data = []
    ): array {
        if ($nodeId < 1 || $expectedVersion < 1 || $expectedParentVersion < 1) {
            throw new InvalidArgumentException('Не указана актуальная версия файла или папки');
        }
        $data = $this->normalize($action, $data);
        $hash = $this->commands->requestHash([
            'node' => $nodeId, 'action' => $action, 'expected_version' => $expectedVersion,
            'expected_parent_version' => $expectedParentVersion, 'data' => $data,
        ]);
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Для операции с файлом нужна отдельная транзакция');
        }

        $this->pdo->beginTransaction();
        try {
            $space = $this->access->space();
            // Единая блокировка пространства сериализует перемещения и исключает
            // циклы, даже если два участника одновременно двигают родительские папки.
            $this->lockSpace((int) $space['id']);
            $node = $this->lockedNode((int) $space['id'], $nodeId);
            $parentId = $node['parent_id'] === null ? null : (int) $node['parent_id'];
            $this->assertAllowed($actorId, $node, $action, $parentId, $data);
            $parentVersion = $this->containerVersion((int) $space['id'], $parentId);

            $receipt = $this->commands->acquire(
                $this->pdo, 'user:' . $actorId, $commandId, 'files',
                'files.node.' . $action, 'file_space_node', (string) $node['uid'], $hash
            );
            if (!$receipt['owner']) {
                $result = $this->commands->storedResponse($receipt['record']);
                $this->pdo->commit();
                return $result;
            }
            if ((int) $node['is_deleted'] === 1 && $action === 'delete') {
                throw new DomainException('Объект уже находится в корзине', 404);
            }
            if ((int) $node['is_deleted'] !== 1 && $action === 'restore') {
                throw new DomainException('Объект не находится в корзине', 404);
            }

            $destinationId = $action === 'move' ? $data['parent_id'] : null;
            $destinationVersion = $action === 'move'
                ? $this->containerVersion((int) $space['id'], $destinationId)
                : null;
            $oldVersion = (int) $node['version'];
            $stale = $oldVersion !== $expectedVersion || $parentVersion !== $expectedParentVersion;
            if ($action === 'move' && $destinationVersion !== $data['expected_destination_version']) {
                $stale = true;
            }
            if ($stale) {
                $result = [
                    'success' => false, 'error' => 'version_conflict',
                    'message' => 'Объект или каталог изменён другим участником. Обновите страницу.',
                    'current_version' => $oldVersion, 'current_parent_version' => $parentVersion,
                    'current_destination_version' => $destinationVersion,
                ];
                $this->finish($receipt, $result, $oldVersion, MutationCommandStore::STATUS_CONFLICT);
                $this->pdo->commit();
                return $result;
            }

            $changed = $this->apply($actorId, (int) $space['id'], $node, $action, $data);
            $newVersion = $oldVersion + 1;
            $newParentVersion = $parentVersion + 1;
            if ($changed) {
                $this->bumpNode($nodeId, $expectedVersion);
                $this->bumpContainer((int) $space['id'], $parentId, $expectedParentVersion);
                if ($action === 'move' && $destinationId !== $parentId) {
                    $this->bumpContainer(
                        (int) $space['id'], $destinationId, (int) $destinationVersion
                    );
                }
                $this->audit((int) $space['id'], $nodeId, $actorId, $action, $data);
            } else {
                $newVersion = $oldVersion;
                $newParentVersion = $parentVersion;
            }
            $result = [
                'success' => true, 'node_id' => $nodeId,
                'version' => $newVersion, 'parent_version' => $newParentVersion,
                'replayed' => false, 'changed' => $changed,
            ];
            $this->finish($receipt, $result, $newVersion, MutationCommandStore::STATUS_APPLIED);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function trash(int $actorId): array
    {
        $space = $this->access->space();
        $stmt = $this->pdo->prepare(
            'SELECT n.id,n.uid,n.name,n.node_type,n.parent_id,n.created_by,n.version,n.deleted_at,
                    n.storage_key,n.byte_size
             FROM file_space_nodes n WHERE n.space_id=:space AND n.is_deleted=1
             ORDER BY n.deleted_at DESC,n.id DESC LIMIT 300'
        );
        $stmt->execute([':space' => (int) $space['id']]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $node) {
            $parent = $node['parent_id'] === null ? null : (int) $node['parent_id'];
            try {
                $this->access->require($actorId, 'global', $parent, 'view');
                $this->access->require($actorId, 'global', $parent, 'restore');
            } catch (DomainException) {
                continue;
            }
            $items[] = [
                'id' => (int) $node['id'],
                'name' => $node['name'],
                'type' => $node['node_type'],
                'version' => (int) $node['version'],
                'parent_id' => $parent,
                'deleted_at' => $node['deleted_at'],
                'parent_version' => $this->containerVersion((int) $space['id'], $parent),
            ];
        }
        return $items;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function normalize(string $action, array $data): array
    {
        if ($action === 'rename') {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 255 || $name === '.' || $name === '..'
                || preg_match('~[/\\\\\x00-\x1F\x7F]~', $name) === 1) {
                throw new InvalidArgumentException('Недопустимое название файла или папки');
            }
            return ['name' => $name];
        }
        if ($action === 'move') {
            $id = $data['parent_id'] ?? null;
            if ($id !== null && (!is_int($id) || $id < 1)) {
                throw new InvalidArgumentException('Некорректная целевая папка');
            }
            $version = $data['expected_destination_version'] ?? null;
            if (!is_int($version) || $version < 1) {
                throw new InvalidArgumentException('Укажите версию целевой папки');
            }
            return ['parent_id' => $id, 'expected_destination_version' => $version];
        }
        if (in_array($action, ['delete', 'restore'], true)) {
            return [];
        }
        throw new InvalidArgumentException('Неизвестное действие с общим объектом');
    }

    /** @param array<string,mixed> $node @param array<string,mixed> $data */
    private function assertAllowed(
        int $actorId, array $node, string $action, ?int $parentId, array $data
    ): void {
        $deleted = (int) $node['is_deleted'] === 1;
        if ($action === 'restore') {
            // Возврат сохранённого ответа разрешён и после восстановления,
            // но только пока действующее право restore сохраняется.
            $this->access->require($actorId, 'global', $parentId, 'view');
            $this->access->require($actorId, 'global', $parentId, 'restore');
            return;
        }
        if ($deleted) {
            if ($action !== 'delete') {
                throw new DomainException('Объект удалён', 404);
            }
            $this->access->require($actorId, 'global', (int) $node['id'], 'view', true);
            $own = $node['created_by'] !== null && (int) $node['created_by'] === $actorId;
            $this->access->require(
                $actorId, 'global', (int) $node['id'],
                $own ? 'delete_own' : 'delete_others', true
            );
            return;
        }
        $this->access->require($actorId, 'global', $node['id'], 'view');
        $own = $node['created_by'] !== null && (int) $node['created_by'] === $actorId;
        $key = match ($action) {
            'rename' => $own ? 'rename_own' : 'rename_others',
            'move' => $own ? 'move_own' : 'move_others',
            'delete' => $own ? 'delete_own' : 'delete_others',
        };
        $this->access->require($actorId, 'global', (int) $node['id'], $key);

        if ($action !== 'move') {
            return;
        }
        $destination = $data['parent_id'];
        $permission = $node['node_type'] === 'folder' ? 'create_folder' : 'upload';
        $this->access->require($actorId, 'global', $destination, 'view');
        $this->access->require($actorId, 'global', $destination, $permission);
        // Перенос папки меняет наследуемые права для всего поддерева.
        // Только управляющий ACL обоих каталогов может переносить такую папку.
        if ($node['node_type'] === 'folder') {
            $this->access->require($actorId, 'global', $parentId, 'manage_acl');
            $this->access->require($actorId, 'global', $destination, 'manage_acl');
        }
    }

    /** @param array<string,mixed> $node @param array<string,mixed> $data */
    private function apply(int $actorId, int $spaceId, array $node, string $action, array $data): bool
    {
        $parent = $node['parent_id'] === null ? null : (int) $node['parent_id'];
        if ($action === 'rename') {
            if ($data['name'] === $node['name']) {
                return false;
            }
            $this->freeName($spaceId, $parent, $data['name'], (int) $node['id']);
            $this->modify((int) $node['id'], 'name=:name', [':name' => $data['name']], $actorId);
            return true;
        }
        if ($action === 'move') {
            $dest = $data['parent_id'];
            if ($dest === $parent) {
                return false;
            }
            $this->assertNoCycle($spaceId, (int) $node['id'], $dest);
            $this->freeName($spaceId, $dest, (string) $node['name'], (int) $node['id']);
            $this->modify((int) $node['id'], 'parent_id=:parent', [':parent' => $dest], $actorId);
            return true;
        }
        if ($action === 'delete') {
            $this->modify((int) $node['id'], 'is_deleted=1,deleted_at=NOW()', [], $actorId);
            return true;
        }
        if ($action === 'restore') {
            $this->freeName($spaceId, $parent, (string) $node['name'], (int) $node['id']);
            $this->modify((int) $node['id'], 'is_deleted=0,deleted_at=NULL', [], $actorId);
            return true;
        }
        throw new InvalidArgumentException('Неизвестная операция');
    }

    private function assertNoCycle(int $spaceId, int $nodeId, ?int $destination): void
    {
        $seen = [];
        $cursor = $destination;
        while ($cursor !== null) {
            if ($cursor === $nodeId || isset($seen[$cursor]) || count($seen) > 64) {
                throw new DomainException('Нельзя перемещать папку внутрь самой себя', 409);
            }
            $seen[$cursor] = true;
            $folder = $this->access->node($spaceId, $cursor);
            if ($folder['node_type'] !== 'folder') {
                throw new DomainException('Цель не является папкой', 404);
            }
            $cursor = $folder['parent_id'] === null ? null : (int) $folder['parent_id'];
        }
    }

    private function freeName(int $spaceId, ?int $parentId, string $name, int $excludedId): void
    {
        $sql = 'SELECT id FROM file_space_nodes
                WHERE space_id=:space AND name=:name AND id<>:excluded
                AND is_deleted=0 AND '
            . ($parentId === null ? 'parent_id IS NULL' : 'parent_id=:parent');
        $stmt = $this->pdo->prepare($sql);
        $args = [':space' => $spaceId, ':name' => $name, ':excluded' => $excludedId];
        if ($parentId !== null) {
            $args[':parent'] = $parentId;
        }
        $stmt->execute($args);
        if ($stmt->fetchColumn() !== false) {
            throw new InvalidArgumentException('Объект с этим названием уже существует в каталоге');
        }
    }

    /** @param array<string,mixed> $args */
    private function modify(int $nodeId, string $clause, array $args, int $actorId): void
    {
        $stmt = $this->pdo->prepare('UPDATE file_space_nodes SET ' . $clause
            . ',updated_by=:actor,updated_at=NOW() WHERE id=:id');
        $stmt->execute([...$args, ':actor' => $actorId, ':id' => $nodeId]);
    }

    private function bumpNode(int $id, int $expected): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE file_space_nodes SET version=version+1 WHERE id=:id AND version=:expected'
        );
        $stmt->execute([':id' => $id, ':expected' => $expected]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Версия объекта устарела');
        }
    }

    private function bumpContainer(int $spaceId, ?int $parent, int $expected): void
    {
        $stmt = $this->pdo->prepare($parent === null
            ? 'UPDATE file_spaces SET version=version+1 WHERE id=:id AND version=:expected'
            : 'UPDATE file_space_nodes SET version=version+1 WHERE id=:id AND version=:expected');
        $stmt->execute([':id' => $parent ?? $spaceId, ':expected' => $expected]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Версия каталога устарела');
        }
    }

    private function containerVersion(int $spaceId, ?int $parent): int
    {
        $stmt = $this->pdo->prepare($parent === null
            ? 'SELECT version FROM file_spaces WHERE id=:id AND is_archived=0'
            : 'SELECT version FROM file_space_nodes WHERE id=:id AND node_type="folder" AND is_deleted=0');
        $stmt->execute([':id' => $parent ?? $spaceId]);
        $v = $stmt->fetchColumn();
        if ($v === false) {
            throw new DomainException('Родительская папка недоступна', 404);
        }
        return (int) $v;
    }

    private function lockSpace(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM file_spaces WHERE id=:id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        if ($stmt->fetchColumn() === false) {
            throw new DomainException('Пространство недоступно', 404);
        }
    }

    /** @return array<string,mixed> */
    private function lockedNode(int $spaceId, int $nodeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM file_space_nodes WHERE id=:id AND space_id=:space LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':id' => $nodeId, ':space' => $spaceId]);
        $node = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($node)) {
            throw new DomainException('Объект не найден', 404);
        }
        return $node;
    }

    /** @param array<string,mixed> $data */
    private function audit(int $spaceId, int $nodeId, int $actorId, string $action, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO file_space_events (space_id,node_id,actor_user_id,event_code,details_json)
             VALUES (:space,:node,:actor,:event,:details)'
        );
        $stmt->execute([
            ':space' => $spaceId, ':node' => $nodeId, ':actor' => $actorId,
            ':event' => 'node.' . $action,
            ':details' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $result */
    private function finish(array $receipt, array $result, int $version, string $status): void
    {
        $this->commands->complete(
            $this->pdo, (int) $receipt['record']['id'], $receipt['token'],
            $status, $result, $version
        );
    }
}
