<?php

declare(strict_types=1);

namespace App\Services\Files;

use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * Централизованная проверка прав файловых пространств.
 * Ни путь на диске, ни скрытая кнопка интерфейса не дают права доступа.
 */
final class FileSpaceAccessService
{
    public const array PERMISSIONS = [
        'view', 'list', 'download', 'upload', 'create_folder',
        'rename_own', 'rename_others', 'delete_own', 'delete_others',
        'move_own', 'move_others', 'replace_own', 'replace_others',
        'share', 'history', 'restore', 'manage_acl',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function space(string $uid = 'global'): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id,uid,space_type,owner_user_id,name,version,is_archived
             FROM file_spaces WHERE uid=:uid LIMIT 1'
        );
        $stmt->execute([':uid' => $uid]);
        $space = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($space) || (int) $space['is_archived'] !== 0) {
            throw new DomainException('Файловое пространство недоступно', 404);
        }
        return $space;
    }

    /** @return array<string,mixed> */
    public function node(int $spaceId, int $nodeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id,uid,space_id,parent_id,node_type,name,created_by,
                    inherit_acl,version,is_deleted
             FROM file_space_nodes
             WHERE space_id=:space_id AND id=:id AND is_deleted=0 LIMIT 1'
        );
        $stmt->execute([':space_id' => $spaceId, ':id' => $nodeId]);
        $node = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($node)) {
            throw new DomainException('Папка или файл недоступны', 404);
        }
        return $node;
    }

    /** @return array<string,mixed> */
    public function actor(int $userId): array
    {
        if ($userId < 1) {
            throw new DomainException('Требуется авторизация', 401);
        }
        $stmt = $this->pdo->prepare(
            "SELECT id,role FROM users WHERE id=:id
             AND is_active=1 AND account_status='active' LIMIT 1"
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            throw new DomainException('Учётная запись недоступна', 403);
        }
        $roles = $this->pdo->prepare(
            'SELECT r.id,r.code FROM roles r
             JOIN user_roles ur ON ur.role_id=r.id
             WHERE ur.user_id=:id'
        );
        $roles->execute([':id' => $userId]);
        $memberships = $roles->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_map('intval', array_column($memberships, 'id'));
        $codes = array_column($memberships, 'code');
        return [
            'id' => $userId,
            'roles' => $ids,
            'is_admin' => in_array('admin', $codes, true)
                || in_array('superadmin', $codes, true)
                || in_array((int) $user['role'], [1, 111], true),
        ];
    }

    /**
     * @param array<string,mixed>|null $target
     * @return array{allowed:bool,source:string}
     */
    public function decision(int $userId, string $spaceUid, ?int $nodeId, string $permission): array
    {
        if (!in_array($permission, self::PERMISSIONS, true)) {
            throw new InvalidArgumentException('Неизвестное файловое право');
        }
        $actor = $this->actor($userId);
        $space = $this->space($spaceUid);
        if ($space['space_type'] === 'personal') {
            $allowed = (int) $space['owner_user_id'] === $userId;
            return ['allowed' => $allowed, 'source' => 'personal_owner'];
        }

        // Администратор сохраняет восстановительный доступ, даже если все правила запрещают операции.
        if ($actor['is_admin']) {
            return ['allowed' => true, 'source' => 'administrator'];
        }

        $chain = $this->chain((int) $space['id'], $nodeId);
        // Чтение закрытого предка не должно обходиться прямым идентификатором дочерней папки.
        foreach (array_reverse($chain) as $scope) {
            if ($scope['key'] === 'space') {
                continue;
            }
            $parent = $scope['parent'];
            if ($parent === null) {
                continue;
            }
            $parentChain = $this->chain((int) $space['id'], $parent);
            $visibility = $this->resolve($actor, (int) $space['id'], $parentChain, 'view');
            if (!$visibility['allowed']) {
                return ['allowed' => false, 'source' => 'ancestor'];
            }
        }

        return $this->resolve($actor, (int) $space['id'], $chain, $permission);
    }

    public function require(int $userId, string $spaceUid, ?int $nodeId, string $permission): void
    {
        $result = $this->decision($userId, $spaceUid, $nodeId, $permission);
        if (!$result['allowed']) {
            throw new DomainException('Объект не найден или недоступен', 404);
        }
    }

    /**
     * Корень всегда завершающий. Предел глубины не допускает циклических ссылок.
     * @return list<array{key:string,parent:?int,author:?int,inherit:bool}>
     */
    private function chain(int $spaceId, ?int $nodeId): array
    {
        $chain = [];
        $seen = [];
        $cursor = $nodeId;
        while ($cursor !== null) {
            if (isset($seen[$cursor]) || count($chain) >= 64) {
                throw new DomainException('Небезопасная структура каталогов', 409);
            }
            $seen[$cursor] = true;
            $node = $this->node($spaceId, $cursor);
            $chain[] = [
                'key' => 'node:' . $cursor,
                'parent' => $node['parent_id'] === null ? null : (int) $node['parent_id'],
                'author' => $node['created_by'] === null ? null : (int) $node['created_by'],
                'inherit' => (int) $node['inherit_acl'] === 1,
            ];
            $cursor = $node['parent_id'] === null ? null : (int) $node['parent_id'];
        }
        $chain[] = ['key' => 'space', 'parent' => null, 'author' => null, 'inherit' => true];
        return $chain;
    }

    /**
     * Ближайшая папка с правилом приоритетнее родителя.
     * На одном уровне явный запрет всегда сильнее разрешения.
     * @param array<string,mixed> $actor
     * @param list<array{key:string,parent:?int,author:?int,inherit:bool}> $chain
     * @return array{allowed:bool,source:string}
     */
    private function resolve(array $actor, int $spaceId, array $chain, string $permission): array
    {
        foreach ($chain as $scope) {
            $stmt = $this->pdo->prepare(
                'SELECT subject_type,subject_id,effect FROM file_space_acl
                 WHERE space_id=:space_id AND scope_key=:scope AND permission_key=:permission'
            );
            $stmt->execute([
                ':space_id' => $spaceId,
                ':scope' => $scope['key'],
                ':permission' => $permission,
            ]);
            $matchedAllow = false;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
                if (!$this->matches($actor, $scope['author'], $rule)) {
                    continue;
                }
                if ($rule['effect'] === 'deny') {
                    return ['allowed' => false, 'source' => $scope['key']];
                }
                $matchedAllow = true;
            }
            if ($matchedAllow) {
                return ['allowed' => true, 'source' => $scope['key']];
            }
            if (!$scope['inherit']) {
                return ['allowed' => false, 'source' => $scope['key']];
            }
        }
        return ['allowed' => false, 'source' => 'default_deny'];
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $rule */
    private function matches(array $actor, ?int $author, array $rule): bool
    {
        $id = (int) $rule['subject_id'];
        return match ($rule['subject_type']) {
            'everyone' => true,
            'user' => $id === $actor['id'],
            'role' => in_array($id, $actor['roles'], true),
            'author' => $author !== null && $author === $actor['id'],
            // Группы будут подключены к общей модели рабочих команд;
            // неизвестное членство никогда не означает разрешение.
            default => false,
        };
    }
}
