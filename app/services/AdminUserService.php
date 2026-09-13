<?php

declare(strict_types=1);

namespace App\Services;

use Core\Config;
use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class AdminUserService
{
    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @return list<array<string,mixed>> */
    public function listUsers(int $actorId): array
    {
        $actor = $this->requireAdmin($actorId);
        $users = $this->db->fetchAll(
            'SELECT id,uid,username,email,firstname,lastname,role,is_active,created_at '
            . 'FROM users ORDER BY id ASC'
        );

        foreach ($users as &$user) {
            $role = (int) $user['role'];
            $isActive = (int) $user['is_active'] === 1;
            $user['role_label'] = $this->roleLabel($role);
            $user['status_code'] = !$isActive || $role === Config::USER_ROLE_INACTIVE
                ? 'inactive'
                : ($role === Config::USER_ROLE_BLOCKED ? 'blocked' : 'active');
            $user['status_label'] = match ($user['status_code']) {
                'inactive' => 'Деактивирован',
                'blocked' => 'Заблокирован',
                default => 'Активен',
            };
            $user['can_manage'] = (int) $user['id'] !== (int) $actor['id']
                && !Config::isAdminRole($role);
        }
        unset($user);

        return $users;
    }

    public function setStatus(int $actorId, int $targetId, string $status): string
    {
        if (!in_array($status, ['active', 'blocked'], true)) {
            throw new InvalidArgumentException('Некорректный статус пользователя', 422);
        }

        $this->requireAdmin($actorId);
        $target = $this->targetUser($targetId);
        $this->assertManageableTarget($actorId, $target);

        if (
            $status === 'blocked'
            && ((int) $target['is_active'] !== 1 || (int) $target['role'] === Config::USER_ROLE_INACTIVE)
        ) {
            throw new DomainException('Сначала активируйте деактивированный аккаунт', 409);
        }

        $this->db->execute(
            'UPDATE users SET role = :role, is_active = 1, updated_at = :updated_at WHERE id = :id',
            [
                ':role' => $status === 'blocked' ? Config::USER_ROLE_BLOCKED : Config::USER_ROLE_USER,
                ':updated_at' => date('Y-m-d H:i:s'),
                ':id' => $targetId,
            ]
        );

        return $status === 'blocked' ? 'Пользователь заблокирован' : 'Пользователь активирован';
    }

    public function deactivate(int $actorId, int $targetId): string
    {
        $this->requireAdmin($actorId);
        $target = $this->targetUser($targetId);
        $this->assertManageableTarget($actorId, $target);

        if ((int) $target['is_active'] !== 1 || (int) $target['role'] === Config::USER_ROLE_INACTIVE) {
            return 'Аккаунт уже деактивирован';
        }

        $ownedGroup = $this->db->fetchOne(
            "SELECT d.uid, COALESCE(NULLIF(d.name, ''), 'Без названия') AS name "
            . 'FROM user_to_dialogs utd '
            . 'JOIN dialogs d ON d.id = utd.dialog_id '
            . "WHERE utd.user_id = :user_id AND utd.role = 'owner' AND utd.is_deleted = 0 AND d.type = 'group' "
            . 'LIMIT 1',
            [':user_id' => $targetId]
        );
        if ($ownedGroup !== null) {
            throw new DomainException(
                'Перед деактивацией передайте владение группой «' . (string) $ownedGroup['name'] . '» другому участнику',
                409
            );
        }

        $this->db->execute(
            'UPDATE users SET is_active = 0, role = :inactive_role, avatar = NULL, updated_at = :updated_at '
            . 'WHERE id = :id AND is_active = 1',
            [
                ':inactive_role' => Config::USER_ROLE_INACTIVE,
                ':updated_at' => date('Y-m-d H:i:s'),
                ':id' => $targetId,
            ]
        );

        try {
            (new UserAvatarService($this->db))->removeStoredAvatar($target);
        } catch (Throwable $e) {
            error_log('Admin user avatar cleanup failed: ' . $e->getMessage());
        }

        return 'Пользователь деактивирован, связанные данные сохранены';
    }

    /** @return array<string,mixed> */
    private function requireAdmin(int $actorId): array
    {
        if ($actorId <= 0) {
            throw new DomainException('Требуется авторизация', 401);
        }

        $actor = $this->db->fetchOne(
            'SELECT id,role,is_active FROM users WHERE id = :id LIMIT 1',
            [':id' => $actorId]
        );
        if (!$actor || (int) $actor['is_active'] !== 1 || !Config::isAdminRole((int) $actor['role'])) {
            throw new DomainException('Недостаточно прав для административной операции', 403);
        }

        return $actor;
    }

    /** @return array<string,mixed> */
    private function targetUser(int $targetId): array
    {
        if ($targetId <= 0) {
            throw new InvalidArgumentException('Не указан пользователь', 422);
        }

        $target = $this->db->fetchOne(
            'SELECT id,uid,username,role,is_active,avatar FROM users WHERE id = :id LIMIT 1',
            [':id' => $targetId]
        );
        if (!$target) {
            throw new DomainException('Пользователь не найден', 404);
        }

        return $target;
    }

    /** @param array<string,mixed> $target */
    private function assertManageableTarget(int $actorId, array $target): void
    {
        if ((int) $target['id'] === $actorId) {
            throw new DomainException('Нельзя изменять собственный административный статус этой операцией', 409);
        }
        if (Config::isAdminRole((int) $target['role'])) {
            throw new DomainException('Административный аккаунт защищён от этой операции', 403);
        }
    }

    private function roleLabel(int $role): string
    {
        return match ($role) {
            Config::USER_ROLE_SUPERADMIN => 'Суперадминистратор',
            Config::USER_ROLE_ADMIN => 'Администратор',
            Config::USER_ROLE_USER, Config::USER_ROLE_INACTIVE, Config::USER_ROLE_BLOCKED => 'Пользователь',
            default => 'Неизвестная роль',
        };
    }
}
