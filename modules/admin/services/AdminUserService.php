<?php

declare(strict_types=1);

namespace App\Services;

require_once dirname(__DIR__, 3) . '/app/services/LicenseSeatPolicy.php';

use App\Helpers\CryptMethods;
use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class AdminUserService
{
    /** @var array<string,string> */
    private const SORT_COLUMNS = [
        'id' => 'id',
        'username' => 'username',
        'email' => 'email',
        'created_at' => 'created_at',
        // Kept for request compatibility while the legacy numeric role column
        // remains present during the 0.14 migration.
        'role' => 'role',
    ];

    private PermissionService $permissions;

    public function __construct(private ?DatabaseManager $db = null, ?PermissionService $permissions = null)
    {
        $this->db ??= DatabaseManager::getInstance();
        $this->permissions = $permissions ?? new PermissionService($this->db);
    }

    /** @return list<array<string,mixed>> */
    public function listUsers(int $actorId): array
    {
        $this->permissions->requirePermission($actorId, 'admin.access');
        $users = $this->db->fetchAll(
            'SELECT id,uid,username,email,firstname,patronymic,lastname,phone,role,is_active,account_status,deletion_requested_at,purge_after,anonymized_at,created_at '
            . 'FROM users ORDER BY id ASC'
        );
        return $this->decorateUsers($users, $actorId);
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function searchUsers(
        int $actorId,
        string $q,
        string $sort,
        string $direction,
        int $limit,
        int $offset
    ): array {
        $this->permissions->requirePermission($actorId, 'admin.access');
        $q = trim($q);
        if (mb_strlen($q) > 100) {
            $q = mb_substr($q, 0, 100);
        }
        $sortColumn = self::SORT_COLUMNS[$sort] ?? self::SORT_COLUMNS['id'];
        $directionSql = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);

        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = '(username LIKE :q_username OR email LIKE :q_email OR firstname LIKE :q_firstname OR lastname LIKE :q_lastname)';
            $needle = '%' . $q . '%';
            $params = [
                ':q_username' => $needle,
                ':q_email' => $needle,
                ':q_firstname' => $needle,
                ':q_lastname' => $needle,
            ];
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM users' . $whereSql, $params);
        $users = $this->db->fetchAll(
            'SELECT id,uid,username,email,firstname,patronymic,lastname,phone,role,is_active,account_status,deletion_requested_at,purge_after,anonymized_at,created_at '
            . 'FROM users' . $whereSql
            . ' ORDER BY ' . $sortColumn . ' ' . $directionSql . ', id ASC'
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        return ['items' => $this->decorateUsers($users, $actorId), 'total' => $total];
    }

    public function setStatus(int $actorId, int $targetId, string $status): string
    {
        if (!in_array($status, ['active', 'blocked'], true)) {
            throw new InvalidArgumentException('Некорректный статус пользователя', 422);
        }

        $this->permissions->requirePermission($actorId, 'admin.users.manage');
        $target = $this->targetUser($targetId);
        $this->assertManageableTarget($actorId, $target);

        if (!empty($target['anonymized_at'])) {
            throw new DomainException('Очищенный аккаунт нельзя активировать или блокировать', 409);
        }
        if ($status === 'active' && !empty($target['deletion_requested_at'])) {
            throw new DomainException('Сначала отмените запланированное удаление аккаунта', 409);
        }

        if (
            $status === 'blocked'
            && ((int) $target['is_active'] !== 1 || (string) $target['account_status'] === 'inactive')
        ) {
            throw new DomainException('Сначала активируйте деактивированный аккаунт', 409);
        }

        $applyStatus = function () use ($status, $targetId): void {
            $this->db->execute(
                'UPDATE users SET account_status = :account_status, is_active = 1, updated_at = :updated_at WHERE id = :id',
                [
                    ':account_status' => $status,
                    ':updated_at' => date('Y-m-d H:i:s'),
                    ':id' => $targetId,
                ]
            );
        };

        $needsSeat = $status === 'active'
            && ((int) $target['is_active'] !== 1 || (string) $target['account_status'] === 'inactive');

        if ($needsSeat) {
            (new LicenseSeatPolicy($this->db))->withAvailableSeat($applyStatus);
        } else {
            $applyStatus();
        }

        return $status === 'blocked' ? 'Пользователь заблокирован' : 'Пользователь активирован';
    }

    public function deactivate(int $actorId, int $targetId): string
    {
        $this->permissions->requirePermission($actorId, 'admin.users.manage');
        $target = $this->targetUser($targetId);
        $this->assertManageableTarget($actorId, $target);

        if ((int) $target['is_active'] !== 1 || (string) $target['account_status'] === 'inactive') {
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
            "UPDATE users SET is_active = 0, account_status = 'inactive', avatar = NULL, updated_at = :updated_at "
            . 'WHERE id = :id AND is_active = 1',
            [
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

    /** @param array<string,mixed> $input */
    public function updateUser(int $actorId, int $targetId, array $input): string
    {
        $this->permissions->requirePermission($actorId, 'admin.users.manage');
        $target = $this->targetUser($targetId);
        $this->assertManageableTarget($actorId, $target);

        if (!empty($target['anonymized_at'])) {
            throw new DomainException('Персональные данные этого аккаунта уже очищены', 409);
        }
        if (!empty($target['deletion_requested_at'])) {
            throw new DomainException('Сначала отмените запланированное удаление аккаунта', 409);
        }

        $username = trim((string) ($input['username'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $firstname = trim((string) ($input['firstname'] ?? ''));
        $patronymic = trim((string) ($input['patronymic'] ?? ''));
        $lastname = trim((string) ($input['lastname'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if (preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) !== 1) {
            throw new InvalidArgumentException('Логин должен содержать 3–50 латинских букв, цифр, точек, дефисов или подчёркиваний', 422);
        }
        if ($email === '' || mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Укажите корректный email', 422);
        }
        if ($firstname === '' || mb_strlen($firstname) > 80) {
            throw new InvalidArgumentException('Укажите корректное имя длиной до 80 символов', 422);
        }
        if ($lastname === '' || mb_strlen($lastname) > 80) {
            throw new InvalidArgumentException('Укажите корректную фамилию длиной до 80 символов', 422);
        }
        if ($patronymic !== '' && mb_strlen($patronymic) > 80) {
            throw new InvalidArgumentException('Отчество слишком длинное', 422);
        }
        if ($phone !== '' && mb_strlen($phone) > 32) {
            throw new InvalidArgumentException('Телефон слишком длинный', 422);
        }
        if ($password !== '' && (strlen($password) < 10 || strlen($password) > 200)) {
            throw new InvalidArgumentException('Новый пароль должен содержать от 10 до 200 символов', 422);
        }

        $duplicate = $this->db->fetchOne(
            'SELECT id FROM users
             WHERE id <> :id AND (username = :username OR email = :email)
             LIMIT 1',
            [':id' => $targetId, ':username' => $username, ':email' => $email]
        );
        if ($duplicate !== null) {
            throw new DomainException('Пользователь с таким логином или email уже существует', 409);
        }

        $sets = [
            'username = :username',
            'email = :email',
            'firstname = :firstname',
            'patronymic = :patronymic',
            'lastname = :lastname',
            'phone = :phone',
            'updated_at = :updated_at',
        ];
        $params = [
            ':username' => $username,
            ':email' => $email,
            ':firstname' => $firstname,
            ':patronymic' => $patronymic !== '' ? $patronymic : null,
            ':lastname' => $lastname,
            ':phone' => $phone !== '' ? $phone : null,
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => $targetId,
        ];
        if ($password !== '') {
            $sets[] = 'password_hash = :password_hash';
            $params[':password_hash'] = CryptMethods::hashPassword($password);
        }

        $this->db->execute(
            'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $params
        );

        return $password === ''
            ? 'Регистрационные данные пользователя обновлены'
            : 'Данные пользователя и пароль обновлены';
    }

    public function requestDeletion(int $actorId, int $targetId, ?int $retentionDays = null): string
    {
        $this->permissions->requirePermission($actorId, 'admin.users.manage');
        $target = $this->targetUser($targetId);
        $this->assertManageableTarget($actorId, $target);

        if (!empty($target['anonymized_at'])) {
            return 'Персональные данные аккаунта уже очищены';
        }

        $ownedGroup = $this->db->fetchOne(
            "SELECT d.uid, COALESCE(NULLIF(d.name, ''), 'Без названия') AS name
             FROM user_to_dialogs utd
             JOIN dialogs d ON d.id = utd.dialog_id
             WHERE utd.user_id = :user_id
               AND utd.role = 'owner'
               AND utd.is_deleted = 0
               AND d.type = 'group'
             LIMIT 1",
            [':user_id' => $targetId]
        );
        if ($ownedGroup !== null) {
            throw new DomainException(
                'Перед удалением передайте владение группой «' . (string) $ownedGroup['name'] . '» или удалите её',
                409
            );
        }

        if ($this->tableExists('task_boards')) {
            $ownedBoard = $this->db->fetchOne(
                "SELECT id,name FROM task_boards
                 WHERE owner_user_id = :user_id
                   AND (audience = 'all_active'
                        OR EXISTS (
                            SELECT 1 FROM task_board_members m
                            WHERE m.board_id = task_boards.id
                              AND m.user_id <> :user_id_member
                        ))
                 LIMIT 1",
                [':user_id' => $targetId, ':user_id_member' => $targetId]
            );
            if ($ownedBoard !== null) {
                throw new DomainException(
                    'Перед удалением передайте владение общей доской задач «'
                    . (string) ($ownedBoard['name'] ?? 'Без названия')
                    . '» или удалите её',
                    409
                );
            }
        }

        if ($retentionDays === null) {
            $configured = trim((string) (getenv('RETENTION_ACCOUNT_DELETE_DAYS') ?: ''));
            $retentionDays = ctype_digit($configured)
                ? (int) $configured
                : UserLifecycleService::DEFAULT_RETENTION_DAYS;
        }
        $retentionDays = max(1, min(365, $retentionDays));

        if ($retentionDays === null) {
            $configured = trim((string) (getenv('RETENTION_ACCOUNT_DELETE_DAYS') ?: '30'));
            $retentionDays = ctype_digit($configured) ? (int) $configured : 30;
        }
        $retentionDays = max(1, min(365, $retentionDays));

        $purgeAfter = (new UserLifecycleService($this->db))->schedule($targetId, $retentionDays);
        return 'Аккаунт помечен на удаление. Персональные данные будут очищены после ' . $purgeAfter;
    }

    public function cancelDeletion(int $actorId, int $targetId): string
    {
        $this->permissions->requirePermission($actorId, 'admin.users.manage');
        $target = $this->targetUser($targetId);
        $this->assertManageableTarget($actorId, $target);

        if (!empty($target['anonymized_at'])) {
            throw new DomainException('Персональные данные уже очищены; отменить удаление нельзя', 409);
        }

        (new UserLifecycleService($this->db))->cancel($targetId);
        return 'Запланированное удаление отменено. Аккаунт остаётся деактивированным.';
    }

    public function purgeDue(int $actorId): string
    {
        $this->permissions->requirePermission($actorId, 'admin.users.manage');
        if (!$this->permissions->hasRole($actorId, 'superadmin')) {
            throw new DomainException('Ручная очистка доступна только суперадминистратору', 403);
        }

        $result = (new UserLifecycleService($this->db))->purgeDue();
        return 'Очищено аккаунтов: ' . (int) $result['processed'];
    }

    /** @param list<array<string,mixed>> $users @return list<array<string,mixed>> */
    private function decorateUsers(array $users, int $actorId): array
    {
        $roleRows = $this->db->fetchAll(
            'SELECT ur.user_id,r.code,r.name FROM user_roles ur '
            . 'JOIN roles r ON r.id = ur.role_id ORDER BY ur.user_id ASC,r.code ASC'
        );
        $rolesByUser = [];
        foreach ($roleRows as $roleRow) {
            $rolesByUser[(int) $roleRow['user_id']][] = [
                'code' => (string) $roleRow['code'],
                'name' => (string) $roleRow['name'],
            ];
        }

        $privilegedRows = $this->db->fetchAll(
            "SELECT DISTINCT ur.user_id FROM user_roles ur "
            . 'JOIN role_permissions rp ON rp.role_id = ur.role_id '
            . 'JOIN permissions p ON p.id = rp.permission_id '
            . "WHERE p.code LIKE 'admin.%'"
        );
        $privileged = [];
        foreach ($privilegedRows as $row) {
            $privileged[(int) $row['user_id']] = true;
        }

        foreach ($users as &$user) {
            $userId = (int) $user['id'];
            $assignedRoles = $rolesByUser[$userId] ?? [];
            $user['role_codes'] = array_values(array_map(
                static fn (array $role): string => $role['code'],
                $assignedRoles
            ));
            $user['role_label'] = $this->roleLabel($assignedRoles);

            $status = (string) ($user['account_status'] ?? 'inactive');
            if ((int) $user['is_active'] !== 1) {
                $status = 'inactive';
            }
            $user['status_code'] = in_array($status, ['active', 'inactive', 'blocked'], true)
                ? $status
                : 'inactive';
            if (!empty($user['anonymized_at'])) {
                $user['status_code'] = 'anonymized';
                $user['status_label'] = 'Персональные данные очищены';
            } elseif (!empty($user['deletion_requested_at'])) {
                $user['status_code'] = 'deletion_pending';
                $user['status_label'] = 'Удаление запланировано';
            } else {
                $user['status_label'] = match ($user['status_code']) {
                    'inactive' => 'Деактивирован',
                    'blocked' => 'Заблокирован',
                    default => 'Активен',
                };
            }
            $user['can_manage'] = $userId !== $actorId
                && !isset($privileged[$userId])
                && empty($user['anonymized_at']);
        }
        unset($user);
        return $users;
    }

    /** @return array<string,mixed> */
    private function targetUser(int $targetId): array
    {
        if ($targetId <= 0) {
            throw new InvalidArgumentException('Не указан пользователь', 422);
        }

        $target = $this->db->fetchOne(
            'SELECT id,uid,username,email,firstname,patronymic,lastname,phone,role,is_active,account_status,avatar,deletion_requested_at,purge_after,anonymized_at FROM users WHERE id = :id LIMIT 1',
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
        $targetId = (int) $target['id'];
        if ($targetId === $actorId) {
            throw new DomainException('Нельзя изменять собственный административный статус этой операцией', 409);
        }

        // Protect privileged identities by assigned authorization, not by their
        // current effective permission. This remains true even while the target
        // is blocked/inactive, when PermissionService intentionally returns no
        // effective capabilities.
        $hasAdministrativeAssignment = $this->db->fetchValue(
            "SELECT 1 FROM user_roles ur "
            . 'JOIN role_permissions rp ON rp.role_id = ur.role_id '
            . 'JOIN permissions p ON p.id = rp.permission_id '
            . "WHERE ur.user_id = :user_id AND p.code LIKE 'admin.%' LIMIT 1",
            [':user_id' => $targetId]
        ) !== null;
        if ($hasAdministrativeAssignment) {
            throw new DomainException('Административный аккаунт защищён от этой операции', 403);
        }
    }

    private function tableExists(string $table): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1',
            [':table_name' => $table]
        ) !== null;
    }

    /** @param list<array{code:string,name:string}> $roles */
    private function roleLabel(array $roles): string
    {
        $byCode = [];
        foreach ($roles as $role) {
            $byCode[$role['code']] = $role['name'];
        }
        foreach (['superadmin', 'admin', 'user'] as $preferred) {
            if (isset($byCode[$preferred])) {
                return $byCode[$preferred];
            }
        }
        if ($roles !== []) {
            return implode(', ', array_values(array_map(
                static fn (array $role): string => $role['name'],
                $roles
            )));
        }
        return 'Без роли';
    }
}
