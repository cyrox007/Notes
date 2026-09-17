<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;

final class MessengerGroupService
{
    private const ROLES = ['owner', 'admin', 'member'];

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @return array<string,mixed> */
    public function info(string $userUid, string $dialogUid): array
    {
        $context = $this->context($userUid, $dialogUid);
        return $this->snapshot($context);
    }

    /** @return array<string,mixed> */
    public function rename(string $userUid, string $dialogUid, string $name): array
    {
        $context = $this->context($userUid, $dialogUid);
        $this->requireManager($context);

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Название группы должно содержать от 1 до 120 символов');
        }

        $this->db->execute(
            'UPDATE dialogs SET name = :name, updated_at = :updated_at WHERE id = :dialog_id',
            [
                ':name' => $name,
                ':updated_at' => date('Y-m-d H:i:s'),
                ':dialog_id' => (int) $context['dialog_id'],
            ]
        );

        return $this->snapshot($context);
    }

    /** @param list<string> $memberUids
     *  @return array<string,mixed>
     */
    public function addMembers(string $userUid, string $dialogUid, array $memberUids): array
    {
        $context = $this->context($userUid, $dialogUid);
        $this->requireManager($context);

        $memberUids = array_values(array_unique(array_filter(array_map(
            static fn ($uid): string => trim((string) $uid),
            $memberUids
        ), static fn (string $uid): bool => $uid !== '')));

        if ($memberUids === []) {
            throw new InvalidArgumentException('Не выбраны участники');
        }
        if (count($memberUids) > 100) {
            throw new InvalidArgumentException('За один раз можно добавить не более 100 участников');
        }

        $users = $this->activeUsers($memberUids);
        if (count($users) !== count($memberUids)) {
            throw new InvalidArgumentException('Один или несколько пользователей недоступны');
        }

        $dialogId = (int) $context['dialog_id'];
        $now = date('Y-m-d H:i:s');
        $latestMessageId = (int) ($this->db->fetchValue(
            'SELECT COALESCE(MAX(id), 0) FROM messages WHERE dialog_id = :dialog_id',
            [':dialog_id' => $dialogId]
        ) ?? 0);

        $this->db->beginTransaction();
        try {
            foreach ($users as $user) {
                $existing = $this->db->fetchOne(
                    'SELECT id, is_deleted FROM user_to_dialogs
                     WHERE dialog_id = :dialog_id AND user_id = :user_id LIMIT 1',
                    [':dialog_id' => $dialogId, ':user_id' => (int) $user['id']]
                );

                if ($existing && (int) $existing['is_deleted'] === 0) {
                    continue;
                }

                if ($existing) {
                    $this->db->execute(
                        'UPDATE user_to_dialogs
                         SET role = :role, joined_at = :joined_at,
                             last_delivered_message_id = :cursor,
                             last_read_message_id = :cursor,
                             is_deleted = 0, archived_at = NULL, muted_until = NULL, pinned_at = NULL
                         WHERE id = :id',
                        [
                            ':role' => 'member',
                            ':joined_at' => $now,
                            ':cursor' => $latestMessageId ?: null,
                            ':id' => (int) $existing['id'],
                        ]
                    );
                } else {
                    $this->db->execute(
                        'INSERT INTO user_to_dialogs (
                            dialog_id, user_id, role, joined_at,
                            last_delivered_message_id, last_read_message_id, is_deleted
                         ) VALUES (
                            :dialog_id, :user_id, :role, :joined_at,
                            :delivered, :read_cursor, 0
                         )',
                        [
                            ':dialog_id' => $dialogId,
                            ':user_id' => (int) $user['id'],
                            ':role' => 'member',
                            ':joined_at' => $now,
                            ':delivered' => $latestMessageId ?: null,
                            ':read_cursor' => $latestMessageId ?: null,
                        ]
                    );
                }
            }
            $this->touchDialog($dialogId);
            $this->db->endTransaction(true);
        } catch (\Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }

        return $this->snapshot($context);
    }

    /** @return array<string,mixed> */
    public function removeMember(string $userUid, string $dialogUid, string $memberUid): array
    {
        $context = $this->context($userUid, $dialogUid);
        $this->requireManager($context);
        $target = $this->member($context, $memberUid);

        if ($target['role'] === 'owner') {
            throw new DomainException('Владельца нельзя удалить из группы');
        }
        if ($context['role'] === 'admin' && $target['role'] !== 'member') {
            throw new DomainException('Администратор может удалять только обычных участников');
        }
        if ((int) $target['user_id'] === (int) $context['user_id']) {
            throw new DomainException('Для выхода из группы используйте отдельное действие');
        }

        $this->deactivateMembership((int) $target['membership_id']);
        $this->touchDialog((int) $context['dialog_id']);
        return $this->snapshot($context);
    }

    /** @return array<string,mixed> */
    public function setRole(string $userUid, string $dialogUid, string $memberUid, string $role): array
    {
        $context = $this->context($userUid, $dialogUid);
        if ($context['role'] !== 'owner') {
            throw new DomainException('Только владелец может назначать администраторов');
        }

        $role = strtolower(trim($role));
        if (!in_array($role, ['admin', 'member'], true)) {
            throw new InvalidArgumentException('Допустимые роли: admin или member');
        }

        $target = $this->member($context, $memberUid);
        if ($target['role'] === 'owner') {
            throw new DomainException('Роль владельца изменяется только передачей владения');
        }

        $this->db->execute(
            'UPDATE user_to_dialogs SET role = :role WHERE id = :id AND is_deleted = 0',
            [':role' => $role, ':id' => (int) $target['membership_id']]
        );
        $this->touchDialog((int) $context['dialog_id']);
        return $this->snapshot($context);
    }

    /** @return array<string,mixed> */
    public function transferOwner(string $userUid, string $dialogUid, string $memberUid): array
    {
        $context = $this->context($userUid, $dialogUid);
        if ($context['role'] !== 'owner') {
            throw new DomainException('Только текущий владелец может передать группу');
        }

        $target = $this->member($context, $memberUid);
        if ((int) $target['user_id'] === (int) $context['user_id']) {
            throw new InvalidArgumentException('Вы уже владелец группы');
        }

        $this->db->beginTransaction();
        try {
            $this->db->execute(
                'UPDATE user_to_dialogs SET role = :role WHERE id = :id AND is_deleted = 0',
                [':role' => 'admin', ':id' => (int) $context['membership_id']]
            );
            $this->db->execute(
                'UPDATE user_to_dialogs SET role = :role WHERE id = :id AND is_deleted = 0',
                [':role' => 'owner', ':id' => (int) $target['membership_id']]
            );
            $this->db->execute(
                'UPDATE dialogs SET created_by = :owner_id, updated_at = :updated_at WHERE id = :dialog_id',
                [
                    ':owner_id' => (int) $target['user_id'],
                    ':updated_at' => date('Y-m-d H:i:s'),
                    ':dialog_id' => (int) $context['dialog_id'],
                ]
            );
            $this->db->endTransaction(true);
        } catch (\Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }

        return $this->snapshot($this->context($userUid, $dialogUid));
    }

    /** @return array{dialog_uid:string,left:bool} */
    public function leave(string $userUid, string $dialogUid): array
    {
        $context = $this->context($userUid, $dialogUid);
        if ($context['role'] === 'owner') {
            throw new DomainException('Перед выходом передайте права владельца другому участнику');
        }

        $this->deactivateMembership((int) $context['membership_id']);
        $this->touchDialog((int) $context['dialog_id']);
        return ['dialog_uid' => $dialogUid, 'left' => true];
    }

    /** @return array<string,mixed> */
    private function context(string $userUid, string $dialogUid): array
    {
        $row = $this->db->fetchOne(
            'SELECT
                u.id AS user_id,
                utd.id AS membership_id,
                utd.role,
                d.id AS dialog_id,
                d.uid AS dialog_uid,
                d.type,
                d.name,
                d.avatar,
                d.created_by
             FROM users u
             INNER JOIN user_to_dialogs utd
                ON utd.user_id = u.id AND utd.is_deleted = 0
             INNER JOIN dialogs d ON d.id = utd.dialog_id
             WHERE u.uid = :user_uid
               AND u.is_active = 1
               AND d.uid = :dialog_uid
             LIMIT 1',
            [':user_uid' => $userUid, ':dialog_uid' => $dialogUid]
        );

        if (!$row) {
            throw new DomainException('Нет доступа к диалогу');
        }
        if ($row['type'] !== 'group') {
            throw new DomainException('Это действие доступно только для групповых чатов');
        }
        if (!in_array((string) $row['role'], self::ROLES, true)) {
            throw new DomainException('Некорректная роль участника');
        }
        return $row;
    }

    private function requireManager(array $context): void
    {
        if (!in_array($context['role'], ['owner', 'admin'], true)) {
            throw new DomainException('Недостаточно прав для управления группой');
        }
    }

    /** @return array<string,mixed> */
    private function member(array $context, string $memberUid): array
    {
        $memberUid = trim($memberUid);
        if ($memberUid === '') {
            throw new InvalidArgumentException('Не указан участник');
        }

        $row = $this->db->fetchOne(
            'SELECT
                utd.id AS membership_id,
                utd.user_id,
                utd.role,
                u.uid,
                u.username,
                u.firstname,
                u.lastname,
                u.avatar
             FROM user_to_dialogs utd
             INNER JOIN users u ON u.id = utd.user_id
             WHERE utd.dialog_id = :dialog_id
               AND utd.is_deleted = 0
               AND u.uid = :member_uid
             LIMIT 1',
            [':dialog_id' => (int) $context['dialog_id'], ':member_uid' => $memberUid]
        );
        if (!$row) {
            throw new DomainException('Участник группы не найден');
        }
        return $row;
    }

    /** @param list<string> $uids
     *  @return list<array<string,mixed>>
     */
    private function activeUsers(array $uids): array
    {
        $placeholders = [];
        $params = [];
        foreach ($uids as $index => $uid) {
            $key = ':uid_' . $index;
            $placeholders[] = $key;
            $params[$key] = $uid;
        }

        return $this->db->fetchAll(
            'SELECT id, uid, username, firstname, lastname, avatar
             FROM users
             WHERE is_active = 1 AND uid IN (' . implode(',', $placeholders) . ')',
            $params
        );
    }

    private function deactivateMembership(int $membershipId): void
    {
        $this->db->execute(
            'UPDATE user_to_dialogs
             SET is_deleted = 1, archived_at = NULL, muted_until = NULL, pinned_at = NULL
             WHERE id = :id',
            [':id' => $membershipId]
        );
    }

    private function touchDialog(int $dialogId): void
    {
        $this->db->execute(
            'UPDATE dialogs SET updated_at = :updated_at WHERE id = :dialog_id',
            [':updated_at' => date('Y-m-d H:i:s'), ':dialog_id' => $dialogId]
        );
    }

    /** @return array<string,mixed> */
    private function snapshot(array $context): array
    {
        $dialog = $this->db->fetchOne(
            'SELECT uid, name, avatar FROM dialogs WHERE id = :dialog_id LIMIT 1',
            [':dialog_id' => (int) $context['dialog_id']]
        );
        if (!$dialog) {
            throw new DomainException('Группа не найдена');
        }

        $members = $this->db->fetchAll(
            'SELECT
                u.uid,
                u.username,
                u.firstname,
                u.lastname,
                u.avatar,
                utd.role,
                utd.joined_at
             FROM user_to_dialogs utd
             INNER JOIN users u ON u.id = utd.user_id
             WHERE utd.dialog_id = :dialog_id
               AND utd.is_deleted = 0
               AND u.is_active = 1
             ORDER BY FIELD(utd.role, "owner", "admin", "member"), utd.id ASC',
            [':dialog_id' => (int) $context['dialog_id']]
        );

        return [
            'dialog_uid' => (string) $dialog['uid'],
            'name' => (string) ($dialog['name'] ?? ''),
            'avatar' => $dialog['avatar'] ?? null,
            'current_role' => (string) $context['role'],
            'members' => $members,
        ];
    }
}
