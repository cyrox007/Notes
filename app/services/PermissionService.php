<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;

/**
 * Central RBAC read boundary.
 *
 * Resource ownership remains a module responsibility. A global permission such
 * as notes.use never authorizes access to another user's note by itself.
 */
final class PermissionService
{
    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    public function hasPermission(int $userId, string $permissionCode): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $permissionCode = $this->normalizePermissionCode($permissionCode);

        $account = $this->db->fetchOne(
            'SELECT is_active,account_status FROM users WHERE id = :id LIMIT 1',
            [':id' => $userId]
        );
        if (
            !$account
            || (int) $account['is_active'] !== 1
            || (string) $account['account_status'] !== 'active'
        ) {
            return false;
        }

        // Unknown permission codes fail closed, including for superadmin. This
        // catches typos and prevents an accidental arbitrary-string bypass.
        $permissionId = $this->db->fetchValue(
            'SELECT id FROM permissions WHERE code = :code LIMIT 1',
            [':code' => $permissionCode]
        );
        if ($permissionId === null) {
            return false;
        }

        if ($this->hasRole($userId, 'superadmin')) {
            return true;
        }

        $allowed = $this->db->fetchValue(
            'SELECT 1 '
            . 'FROM user_roles ur '
            . 'JOIN role_permissions rp ON rp.role_id = ur.role_id '
            . 'JOIN permissions p ON p.id = rp.permission_id '
            . 'WHERE ur.user_id = :user_id AND p.code = :permission '
            . 'LIMIT 1',
            [':user_id' => $userId, ':permission' => $permissionCode]
        );

        return $allowed !== null;
    }

    public function requirePermission(int $userId, string $permissionCode): void
    {
        if ($userId <= 0) {
            throw new DomainException('Требуется авторизация', 401);
        }
        if (!$this->hasPermission($userId, $permissionCode)) {
            throw new DomainException('Недостаточно прав для операции', 403);
        }
    }

    public function hasRole(int $userId, string $roleCode): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $roleCode = $this->normalizeRoleCode($roleCode);

        return $this->db->fetchValue(
            'SELECT 1 FROM user_roles ur '
            . 'JOIN roles r ON r.id = ur.role_id '
            . 'WHERE ur.user_id = :user_id AND r.code = :role LIMIT 1',
            [':user_id' => $userId, ':role' => $roleCode]
        ) !== null;
    }

    /** @return list<string> */
    public function roleCodesForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT r.code FROM user_roles ur '
            . 'JOIN roles r ON r.id = ur.role_id '
            . 'WHERE ur.user_id = :user_id ORDER BY r.code ASC',
            [':user_id' => $userId]
        );
        return array_values(array_map(static fn (array $row): string => (string) $row['code'], $rows));
    }

    /** @return list<string> */
    public function permissionsForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $account = $this->db->fetchOne(
            'SELECT is_active,account_status FROM users WHERE id = :id LIMIT 1',
            [':id' => $userId]
        );
        if (
            !$account
            || (int) $account['is_active'] !== 1
            || (string) $account['account_status'] !== 'active'
        ) {
            return [];
        }

        if ($this->hasRole($userId, 'superadmin')) {
            $rows = $this->db->fetchAll('SELECT code FROM permissions ORDER BY code ASC');
        } else {
            $rows = $this->db->fetchAll(
                'SELECT DISTINCT p.code '
                . 'FROM user_roles ur '
                . 'JOIN role_permissions rp ON rp.role_id = ur.role_id '
                . 'JOIN permissions p ON p.id = rp.permission_id '
                . 'WHERE ur.user_id = :user_id ORDER BY p.code ASC',
                [':user_id' => $userId]
            );
        }

        return array_values(array_map(static fn (array $row): string => (string) $row['code'], $rows));
    }

    private function normalizePermissionCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (preg_match('/^[a-z][a-z0-9_-]{1,63}\.[a-z][a-z0-9_.-]{0,95}$/', $code) !== 1) {
            throw new InvalidArgumentException('Invalid permission code');
        }
        return $code;
    }

    private function normalizeRoleCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $code) !== 1) {
            throw new InvalidArgumentException('Invalid role code');
        }
        return $code;
    }
}
