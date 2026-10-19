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
    public function node(int $spaceId, int $nodeId, bool $includeDeleted = false): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id,uid,space_id,parent_id,node_type,name,created_by,
                    inherit_acl,version,is_deleted
             FROM file_space_nodes
             WHERE space_id=:space_id AND id=:id AND ' . ($includeDeleted ? '1=1' : 'is_deleted=0') . ' LIMIT 1'
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
        $groupQuery = $this->pdo->prepare(
            'SELECT group_id FROM file_access_group_members WHERE user_id=:id'
        );
        $groupQuery->execute([':id' => $userId]);
        $groups = array_map('intval', $groupQuery->fetchAll(PDO::FETCH_COLUMN));
        $ids = array_map('intval', array_column($memberships, 'id'));
        $codes = array_column($memberships, 'code');
        return [
            'id' => $userId,
            'roles' => $ids,
            'groups' => $groups,
            'is_admin' => in_array('admin', $codes, true)
                || in_array('superadmin', $codes, true)
                || in_array((int) $user['role'], [1, 111], true),
        ];
    }

    /**
     * @param array<string,mixed>|null $target
     * @return array{allowed:bool,source:string}
     */
    public function decision(int $userId, string $spaceUid, ?int $nodeId, string $permission, bool $includeDeletedTarget = false): array
    {
        return $this->decisions(
            $userId, $spaceUid, $nodeId, [$permission], $includeDeletedTarget
        )[$permission];
    }

    /**
     * Проверяет несколько прав в рамках одной операции без повторных чтений
     * пользователя, предков и одинаковых правил. Данные не кешируются между
     * вызовами: изменение ACL вступает в силу для следующей операции.
     *
     * @param list<string> $permissions
     * @return array<string,array{allowed:bool,source:string,subject?:string}>
     */
    public function decisions(
        int $userId, string $spaceUid, ?int $nodeId, array $permissions,
        bool $includeDeletedTarget = false
    ): array {
        $permissions = array_values(array_unique($permissions));
        foreach ($permissions as $permission) {
            if (!in_array($permission, self::PERMISSIONS, true)) {
                throw new InvalidArgumentException('Неизвестное файловое право');
            }
        }
        if ($permissions === []) {
            return [];
        }

        $actor = $this->actor($userId);
        $space = $this->space($spaceUid);
        if ($space['space_type'] === 'personal') {
            $allowed = (int) $space['owner_user_id'] === $userId;
            return array_fill_keys($permissions, [
                'allowed' => $allowed, 'source' => 'personal_owner',
            ]);
        }

        // Цепочка проверяется и для администратора: удалённые предки
        // не должны становиться доступными через прямой маршрут.
        $chain = $this->chain((int) $space['id'], $nodeId, $includeDeletedTarget);
        if ($actor['is_admin']) {
            return array_fill_keys($permissions, [
                'allowed' => true, 'source' => 'administrator',
                'subject' => 'administrator',
            ]);
        }

        $scopes = array_values(array_unique(array_column($chain, 'key')));
        $rights = array_values(array_unique([...$permissions, 'view']));
        $scopeMarks = implode(',', array_fill(0, count($scopes), '?'));
        $rightMarks = implode(',', array_fill(0, count($rights), '?'));
        $query = $this->pdo->prepare(
            'SELECT scope_key,permission_key,subject_type,subject_id,effect
             FROM file_space_acl WHERE space_id=?
               AND scope_key IN (' . $scopeMarks . ')
               AND permission_key IN (' . $rightMarks . ')'
        );
        $query->execute([(int) $space['id'], ...$scopes, ...$rights]);
        $rules = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            $rules[$rule['scope_key']][$rule['permission_key']][] = $rule;
        }

        if ($nodeId !== null) {
            $ancestors = array_slice($chain, 1);
            foreach ($ancestors as $index => $_scope) {
                $visible = $this->resolveLoaded(
                    $actor, array_slice($ancestors, $index), 'view', $rules
                );
                if (!$visible['allowed']) {
                    return array_fill_keys($permissions, [
                        'allowed' => false, 'source' => 'ancestor',
                        'subject' => 'closed_ancestor',
                    ]);
                }
            }
        }

        $result = [];
        foreach ($permissions as $permission) {
            $result[$permission] = $this->resolveLoaded($actor, $chain, $permission, $rules);
        }
        return $result;
    }

    /**
     * Пакетная проверка прав для дочерних объектов одного каталога.
     * Членство пользователя и набор правил считываются один раз на пачку.
     * Не хранит права между запросами или операциями изменения ACL.
     *
     * @param list<array<string,mixed>> $items
     * @param list<string> $permissions
     * @return array<int,array<string,array{allowed:bool,source:string,subject:string}>>
     */
    public function childrenRights(
        int $userId, ?int $parentId, array $items, array $permissions
    ): array {
        $parentRights = $this->decisions($userId, 'global', $parentId, ['view', 'list']);
        if (!$parentRights['view']['allowed'] || !$parentRights['list']['allowed']) {
            throw new DomainException('Объект не найден или недоступен', 404);
        }
        $permissions = array_values(array_unique($permissions));
        foreach ($permissions as $permission) {
            if (!in_array($permission, self::PERMISSIONS, true)) {
                throw new InvalidArgumentException('Неизвестное файловое право');
            }
        }
        if (count($items) > 100 || count($permissions) > count(self::PERMISSIONS)) {
            throw new InvalidArgumentException('Превышен допустимый размер проверки файловых прав');
        }
        if ($items === []) {
            return [];
        }

        $actor = $this->actor($userId);
        $space = $this->space('global');
        $parentChain = $this->chain((int) $space['id'], $parentId);
        $result = [];

        if ($actor['is_admin']) {
            foreach ($items as $item) {
                $id = (int) $item['id'];
                foreach ($permissions as $permission) {
                    $result[$id][$permission] = [
                        'allowed' => true, 'source' => 'administrator',
                        'subject' => 'administrator',
                    ];
                }
            }
            return $result;
        }

        $scopes = array_column($parentChain, 'key');
        foreach ($items as $item) {
            $scopes[] = 'node:' . (int) $item['id'];
        }
        $scopes = array_values(array_unique($scopes));
        $marks = implode(',', array_fill(0, count($scopes), '?'));
        $rulesQuery = $this->pdo->prepare(
            'SELECT scope_key,permission_key,subject_type,subject_id,effect
             FROM file_space_acl WHERE space_id=?
               AND scope_key IN (' . $marks . ')'
        );
        $rulesQuery->execute([(int) $space['id'], ...$scopes]);
        $rules = [];
        foreach ($rulesQuery->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            $rules[$rule['scope_key']][$rule['permission_key']][] = $rule;
        }

        foreach ($items as $item) {
            $id = (int) $item['id'];
            $chain = [[
                'key' => 'node:' . $id,
                'parent' => $parentId,
                'author' => $item['created_by'] === null ? null : (int) $item['created_by'],
                'inherit' => (int) $item['inherit_acl'] === 1,
            ], ...$parentChain];
            foreach ($permissions as $permission) {
                $result[$id][$permission] = $this->resolveLoaded(
                    $actor, $chain, $permission, $rules
                );
            }
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $actor
     * @param list<array{key:string,parent:?int,author:?int,inherit:bool}> $chain
     * @param array<string,array<string,list<array<string,mixed>>>> $rules
     * @return array{allowed:bool,source:string,subject:string}
     */
    private function resolveLoaded(
        array $actor, array $chain, string $permission, array $rules
    ): array {
        foreach ($chain as $scope) {
            $allowedBy = null;
            foreach ($rules[$scope['key']][$permission] ?? [] as $rule) {
                if (!$this->matches($actor, $scope['author'], $rule)) {
                    continue;
                }
                $subject = (string) $rule['subject_type'];
                if (in_array($subject, ['role', 'group', 'user'], true)) {
                    $subject .= ':' . (int) $rule['subject_id'];
                }
                if ($rule['effect'] === 'deny') {
                    return ['allowed' => false, 'source' => $scope['key'], 'subject' => $subject];
                }
                $allowedBy = $subject;
            }
            if ($allowedBy !== null) {
                return ['allowed' => true, 'source' => $scope['key'], 'subject' => $allowedBy];
            }
            if (!$scope['inherit']) {
                return [
                    'allowed' => false, 'source' => $scope['key'],
                    'subject' => 'inheritance_off',
                ];
            }
        }
        return ['allowed' => false, 'source' => 'default_deny', 'subject' => 'none'];
    }

    /** @return list<array{permission:string,allowed:bool,source:string,subject:string}> */
    public function explain(int $actorId, int $targetUserId, ?int $folderId = null): array
    {
        if (!$this->decision($actorId, 'global', $folderId, 'manage_acl')['allowed']) {
            throw new DomainException('Только управляющий правами может просматривать диагностику', 403);
        }
        $results = [];
        foreach (self::PERMISSIONS as $permission) {
            $decision = $this->decision($targetUserId, 'global', $folderId, $permission);
            $results[] = [
                'permission' => $permission,
                'allowed' => $decision['allowed'],
                'source' => $decision['source'],
                'subject' => $decision['subject'] ?? '',
            ];
        }
        return $results;
    }

    public function require(int $userId, string $spaceUid, ?int $nodeId, string $permission, bool $includeDeletedTarget = false): void
    {
        $result = $this->decision($userId, $spaceUid, $nodeId, $permission, $includeDeletedTarget);
        if (!$result['allowed']) {
            throw new DomainException('Объект не найден или недоступен', 404);
        }
    }

    /**
     * Корень всегда завершающий. Предел глубины не допускает циклических ссылок.
     * @return list<array{key:string,parent:?int,author:?int,inherit:bool}>
     */
    private function chain(int $spaceId, ?int $nodeId, bool $includeDeletedTarget = false): array
    {
        $chain = [];
        $seen = [];
        $cursor = $nodeId;
        while ($cursor !== null) {
            if (isset($seen[$cursor]) || count($chain) >= 64) {
                throw new DomainException('Небезопасная структура каталогов', 409);
            }
            $seen[$cursor] = true;
            $node = $this->node($spaceId, $cursor, $includeDeletedTarget && $cursor === $nodeId);
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
            $matchedSubject = '';
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
                if (!$this->matches($actor, $scope['author'], $rule)) {
                    continue;
                }
                $subject = (string) $rule['subject_type'];
                if (in_array($subject, ['role', 'group', 'user'], true)) {
                    $subject .= ':' . (int) $rule['subject_id'];
                }
                if ($rule['effect'] === 'deny') {
                    return ['allowed' => false, 'source' => $scope['key'], 'subject' => $subject];
                }
                $matchedAllow = true;
                $matchedSubject = $subject;
            }
            if ($matchedAllow) {
                return ['allowed' => true, 'source' => $scope['key'], 'subject' => $matchedSubject];
            }
            if (!$scope['inherit']) {
                return ['allowed' => false, 'source' => $scope['key'], 'subject' => 'inheritance_off'];
            }
        }
        return ['allowed' => false, 'source' => 'default_deny', 'subject' => 'none'];
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $rule */
    private function matches(array $actor, ?int $author, array $rule): bool
    {
        $id = (int) $rule['subject_id'];
        return match ($rule['subject_type']) {
            'everyone' => true,
            'user' => $id === $actor['id'],
            'role' => in_array($id, $actor['roles'], true),
            'group' => in_array($id, $actor['groups'], true),
            'author' => $author !== null && $author === $actor['id'],
            default => false,
        };
    }
}
