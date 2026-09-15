<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class RoleManagementService
{
    private const SYSTEM_ROLES = ['superadmin', 'admin', 'user'];
    private PermissionService $permissions;
    private RolePolicyService $policies;

    public function __construct(
        private ?DatabaseManager $db = null,
        ?PermissionService $permissions = null,
        ?RolePolicyService $policies = null
    ) {
        $this->db ??= DatabaseManager::getInstance();
        $this->permissions = $permissions ?? new PermissionService($this->db);
        $this->policies = $policies ?? new RolePolicyService($this->db, $this->permissions);
    }

    /** @return array{roles:list<array<string,mixed>>,permissions:list<array<string,mixed>>,users:list<array<string,mixed>>,policy_definitions:array<string,array<string,array<string,mixed>>>} */
    public function snapshot(int $actorId): array
    {
        $this->requireRoleManager($actorId);

        $permissionRows = $this->db->fetchAll(
            'SELECT id,code,module_id,description FROM permissions ORDER BY module_id ASC,code ASC'
        );
        $rolePermissionRows = $this->db->fetchAll(
            'SELECT rp.role_id,p.code FROM role_permissions rp '
            . 'JOIN permissions p ON p.id = rp.permission_id ORDER BY rp.role_id,p.code'
        );
        $permissionCodesByRole = [];
        foreach ($rolePermissionRows as $row) {
            $permissionCodesByRole[(int) $row['role_id']][] = (string) $row['code'];
        }

        $roleRows = $this->db->fetchAll(
            'SELECT id,code,name,description,is_system,created_at,updated_at FROM roles '
            . "ORDER BY FIELD(code,'superadmin','admin','user') DESC,is_system DESC,name ASC,id ASC"
        );
        $roles = [];
        foreach ($roleRows as $role) {
            $roleId = (int) $role['id'];
            $roles[] = [
                'id' => $roleId,
                'code' => (string) $role['code'],
                'name' => (string) $role['name'],
                'description' => (string) ($role['description'] ?? ''),
                'is_system' => (int) $role['is_system'] === 1,
                'permission_codes' => $permissionCodesByRole[$roleId] ?? [],
                'policies' => $this->policies->rolePolicies($roleId),
                'assigned_users' => (int) $this->db->fetchValue(
                    'SELECT COUNT(*) FROM user_roles WHERE role_id = :role_id',
                    [':role_id' => $roleId]
                ),
            ];
        }

        $assignmentRows = $this->db->fetchAll(
            'SELECT ur.user_id,ur.role_id FROM user_roles ur ORDER BY ur.user_id,ur.role_id'
        );
        $assignments = [];
        foreach ($assignmentRows as $row) {
            $assignments[(int) $row['user_id']][] = (int) $row['role_id'];
        }

        $users = $this->db->fetchAll(
            'SELECT id,username,email,firstname,lastname,is_active,account_status FROM users ORDER BY username ASC,id ASC'
        );
        foreach ($users as &$user) {
            $userId = (int) $user['id'];
            $user['role_ids'] = $assignments[$userId] ?? [];
            $user['is_self'] = $userId === $actorId;
        }
        unset($user);

        return [
            'roles' => $roles,
            'permissions' => $permissionRows,
            'users' => $users,
            'policy_definitions' => RolePolicyService::definitions(),
        ];
    }

    public function createRole(int $actorId, string $code, string $name, string $description): int
    {
        $this->requireRoleManager($actorId);
        $code = strtolower(trim($code));
        $name = trim($name);
        $description = trim($description);

        if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $code) !== 1) {
            throw new InvalidArgumentException('Код роли: 2–64 символа, латиница, цифры, точка, _ и -', 422);
        }
        if (in_array($code, self::SYSTEM_ROLES, true)) {
            throw new DomainException('Системный код роли зарезервирован', 409);
        }
        $this->validateRoleText($name, $description);
        if ($this->db->fetchValue('SELECT 1 FROM roles WHERE code = :code LIMIT 1', [':code' => $code]) !== null) {
            throw new DomainException('Роль с таким кодом уже существует', 409);
        }

        $this->db->execute(
            'INSERT INTO roles (code,name,description,is_system) VALUES (:code,:name,:description,0)',
            [':code' => $code, ':name' => $name, ':description' => $description !== '' ? $description : null]
        );
        return (int) $this->db->getPdo()->lastInsertId();
    }

    /** @param list<string> $permissionCodes */
    public function updateRole(
        int $actorId,
        int $roleId,
        string $name,
        string $description,
        array $permissionCodes
    ): void {
        $this->requireRoleManager($actorId);
        $role = $this->role($roleId);
        $roleCode = (string) $role['code'];
        if ($roleCode === 'superadmin') {
            throw new DomainException('Системная роль superadmin имеет полный доступ и не редактируется', 409);
        }

        $name = trim($name);
        $description = trim($description);
        $this->validateRoleText($name, $description);
        $permissionCodes = $this->validatePermissionCodes($permissionCodes);
        if (in_array('admin.roles.manage', $permissionCodes, true)) {
            throw new DomainException('Право admin.roles.manage закреплено только за системным superadmin', 403);
        }

        $this->db->beginTransaction();
        try {
            if ((int) $role['is_system'] !== 1) {
                $this->db->execute(
                    'UPDATE roles SET name = :name,description = :description WHERE id = :id',
                    [':name' => $name, ':description' => $description !== '' ? $description : null, ':id' => $roleId]
                );
            }

            $this->db->execute('DELETE FROM role_permissions WHERE role_id = :role_id', [':role_id' => $roleId]);
            foreach ($permissionCodes as $code) {
                $this->db->execute(
                    'INSERT INTO role_permissions (role_id,permission_id) '
                    . 'SELECT :role_id,id FROM permissions WHERE code = :code',
                    [':role_id' => $roleId, ':code' => $code]
                );
            }
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    public function deleteRole(int $actorId, int $roleId): void
    {
        $this->requireRoleManager($actorId);
        $role = $this->role($roleId);
        if ((int) $role['is_system'] === 1) {
            throw new DomainException('Системную роль удалить нельзя', 409);
        }
        $assigned = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM user_roles WHERE role_id = :role_id',
            [':role_id' => $roleId]
        );
        if ($assigned > 0) {
            throw new DomainException('Сначала снимите роль со всех пользователей', 409);
        }
        $this->db->execute('DELETE FROM roles WHERE id = :id', [':id' => $roleId]);
    }

    /** @param list<int|string> $roleIds */
    public function assignRoles(int $actorId, int $targetUserId, array $roleIds): void
    {
        $this->requireRoleManager($actorId);
        if ($targetUserId <= 0) {
            throw new InvalidArgumentException('Не указан пользователь', 422);
        }
        if ($targetUserId === $actorId) {
            throw new DomainException('Собственные роли меняются только через процедуру передачи владельца установки', 409);
        }
        if ($this->db->fetchValue('SELECT 1 FROM users WHERE id = :id LIMIT 1', [':id' => $targetUserId]) === null) {
            throw new DomainException('Пользователь не найден', 404);
        }

        $normalizedIds = [];
        foreach ($roleIds as $roleId) {
            $id = (int) $roleId;
            if ($id > 0) {
                $normalizedIds[$id] = true;
            }
        }
        $normalizedIds = array_keys($normalizedIds);
        if ($normalizedIds === []) {
            throw new InvalidArgumentException('У пользователя должна остаться хотя бы одна роль', 422);
        }

        $placeholders = [];
        $params = [];
        foreach ($normalizedIds as $index => $id) {
            $placeholder = ':role_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $id;
        }
        $validRoles = $this->db->fetchAll(
            'SELECT id,code FROM roles WHERE id IN (' . implode(',', $placeholders) . ')',
            $params
        );
        if (count($validRoles) !== count($normalizedIds)) {
            throw new InvalidArgumentException('Одна из выбранных ролей не существует', 422);
        }

        $newCodes = [];
        foreach ($validRoles as $role) {
            $newCodes[(string) $role['code']] = true;
        }
        $hadSuperadmin = $this->permissions->hasRole($targetUserId, 'superadmin');
        if ($hadSuperadmin && !isset($newCodes['superadmin'])) {
            $otherActiveOwners = (int) $this->db->fetchValue(
                "SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur "
                . 'JOIN roles r ON r.id = ur.role_id JOIN users u ON u.id = ur.user_id '
                . "WHERE r.code = 'superadmin' AND u.is_active = 1 AND u.account_status = 'active' AND ur.user_id <> :target",
                [':target' => $targetUserId]
            );
            if ($otherActiveOwners < 1) {
                throw new DomainException('Нельзя удалить последнего активного суперадминистратора', 409);
            }
        }

        $this->db->beginTransaction();
        try {
            $this->db->execute('DELETE FROM user_roles WHERE user_id = :user_id', [':user_id' => $targetUserId]);
            foreach ($normalizedIds as $roleId) {
                $this->db->execute(
                    'INSERT INTO user_roles (user_id,role_id,assigned_by) VALUES (:user_id,:role_id,:assigned_by)',
                    [':user_id' => $targetUserId, ':role_id' => $roleId, ':assigned_by' => $actorId]
                );
            }
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    private function role(int $roleId): array
    {
        if ($roleId <= 0) {
            throw new InvalidArgumentException('Не указана роль', 422);
        }
        $role = $this->db->fetchOne(
            'SELECT id,code,name,description,is_system FROM roles WHERE id = :id LIMIT 1',
            [':id' => $roleId]
        );
        if (!$role) {
            throw new DomainException('Роль не найдена', 404);
        }
        return $role;
    }

    private function validateRoleText(string $name, string $description): void
    {
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Название роли должно содержать от 1 до 120 символов', 422);
        }
        if (mb_strlen($description) > 255) {
            throw new InvalidArgumentException('Описание роли не должно превышать 255 символов', 422);
        }
    }

    /** @param list<string> $permissionCodes @return list<string> */
    private function validatePermissionCodes(array $permissionCodes): array
    {
        $normalized = [];
        foreach ($permissionCodes as $code) {
            $value = strtolower(trim((string) $code));
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }
        $codes = array_keys($normalized);
        if ($codes === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($codes as $index => $code) {
            $placeholder = ':permission_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $code;
        }
        $existing = $this->db->fetchAll(
            'SELECT code FROM permissions WHERE code IN (' . implode(',', $placeholders) . ')',
            $params
        );
        if (count($existing) !== count($codes)) {
            throw new InvalidArgumentException('Одна из выбранных возможностей не существует', 422);
        }
        return $codes;
    }

    private function requireRoleManager(int $actorId): void
    {
        $this->permissions->requirePermission($actorId, 'admin.roles.manage');
        if (!$this->permissions->hasRole($actorId, 'superadmin')) {
            throw new DomainException('Управление ролями доступно только суперадминистратору', 403);
        }
    }
}
