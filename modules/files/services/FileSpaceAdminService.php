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
 * Административное управление файловыми группами и сроком хранения.
 * Проверки версии, команды и аудит выполняются в одной транзакции.
 */
final class FileSpaceAdminService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FileSpaceAccessService $access,
        private readonly MutationCommandStore $commands = new MutationCommandStore()
    ) {
    }

    /** @return array<string,mixed> */
    public function snapshot(int $actorId): array
    {
        $this->requireAdmin($actorId);
        $groups = $this->pdo->query(
            'SELECT g.id,g.name,g.version,COUNT(m.user_id) AS members
             FROM file_access_groups g LEFT JOIN file_access_group_members m ON m.group_id=g.id
             GROUP BY g.id,g.name,g.version ORDER BY g.name LIMIT 200'
        )->fetchAll(PDO::FETCH_ASSOC);
        $members = $this->pdo->query(
            'SELECT m.group_id,m.user_id,u.username FROM file_access_group_members m
             JOIN users u ON u.id=m.user_id
             ORDER BY m.group_id,u.username LIMIT 1000'
        )->fetchAll(PDO::FETCH_ASSOC);
        $setting = $this->setting(false);
        $users = $this->pdo->query(
            "SELECT id,username,is_active,account_status FROM users
             ORDER BY username LIMIT 500"
        )->fetchAll(PDO::FETCH_ASSOC);
        $roles = $this->pdo->query(
            'SELECT id,code,name FROM roles ORDER BY name LIMIT 100'
        )->fetchAll(PDO::FETCH_ASSOC);
        return [
            'groups' => $groups,
            'members' => $members,
            'users' => $users,
            'roles' => $roles,
            'retention_days' => (int) $setting['trash_retention_days'],
            'settings_version' => (int) $setting['version'],
        ];
    }

    /** @return array<string,mixed> */
    public function createGroup(int $actorId, string $name, string $commandId): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Название группы должно содержать от 1 до 120 символов');
        }
        return $this->command(
            $actorId, $commandId, 'group.create', hash('sha256', $name), ['name' => $name],
            function () use ($actorId, $name): array {
                $insert = $this->pdo->prepare(
                    'INSERT INTO file_access_groups (name,created_by) VALUES (:name,:actor)'
                );
                $insert->execute([':name' => $name, ':actor' => $actorId]);
                $id = (int) $this->pdo->lastInsertId();
                $this->audit($actorId, 'group.create', ['group_id' => $id, 'name' => $name]);
                return ['success' => true, 'group_id' => $id, 'version' => 1];
            }
        );
    }

    /** @return array<string,mixed> */
    public function changeGroup(
        int $actorId, int $groupId, string $operation, int $memberId,
        int $expectedVersion, string $commandId
    ): array {
        if (!in_array($operation, ['add', 'remove', 'delete'], true) || $groupId < 1
            || $expectedVersion < 1 || ($operation !== 'delete' && $memberId < 1)) {
            throw new InvalidArgumentException('Некорректная команда управления группой');
        }
        return $this->command($actorId, $commandId, 'group.' . $operation, 'group:' . $groupId, [
            'member_id' => $memberId, 'expected_version' => $expectedVersion,
        ], function () use ($actorId, $groupId, $operation, $memberId, $expectedVersion): array {
            $query = $this->pdo->prepare(
                'SELECT id,version FROM file_access_groups WHERE id=:id FOR UPDATE'
            );
            $query->execute([':id' => $groupId]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new DomainException('Группа не найдена', 404);
            }
            if ((int) $row['version'] !== $expectedVersion) {
                return $this->conflict((int) $row['version']);
            }
            if ($operation === 'delete') {
                $acl = $this->pdo->prepare(
                    "DELETE FROM file_space_acl WHERE subject_type='group' AND subject_id=:id"
                );
                $acl->execute([':id' => $groupId]);
                $stmt = $this->pdo->prepare('DELETE FROM file_access_groups WHERE id=:id');
                $stmt->execute([':id' => $groupId]);
                $this->audit($actorId, 'group.delete', ['group_id' => $groupId]);
                return ['success' => true, 'group_id' => $groupId, 'deleted' => true];
            }
            if ($operation === 'add') {
                $active = $this->pdo->prepare(
                    "SELECT id FROM users WHERE id=:id AND is_active=1
                     AND account_status='active' LIMIT 1"
                );
                $active->execute([':id' => $memberId]);
                if ($active->fetchColumn() === false) {
                    throw new InvalidArgumentException('Добавить можно только активного пользователя');
                }
            }
            $stmt = $this->pdo->prepare($operation === 'add'
                ? 'INSERT IGNORE INTO file_access_group_members (group_id,user_id,added_by)
                   VALUES (:group_id,:member_id,:actor)'
                : 'DELETE FROM file_access_group_members
                   WHERE group_id=:group_id AND user_id=:member_id');
            $params = [':group_id' => $groupId, ':member_id' => $memberId];
            if ($operation === 'add') {
                $params[':actor'] = $actorId;
            }
            $stmt->execute($params);
            $changed = $stmt->rowCount() > 0;
            $version = $expectedVersion;
            if ($changed) {
                $update = $this->pdo->prepare(
                    'UPDATE file_access_groups SET version=version+1
                     WHERE id=:id AND version=:version'
                );
                $update->execute([':id' => $groupId, ':version' => $expectedVersion]);
                $version++;
                $this->audit($actorId, 'group.member.' . $operation, [
                    'group_id' => $groupId, 'user_id' => $memberId,
                ]);
            }
            return [
                'success' => true, 'group_id' => $groupId,
                'version' => $version, 'changed' => $changed,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function setRetention(
        int $actorId, int $days, int $expectedVersion, string $commandId
    ): array {
        if ($days < 1 || $days > 3650 || $expectedVersion < 1) {
            throw new InvalidArgumentException('Срок хранения должен составлять от 1 до 3650 дней');
        }
        return $this->command($actorId, $commandId, 'retention.set', 'global', [
            'days' => $days, 'expected_version' => $expectedVersion,
        ], function () use ($actorId, $days, $expectedVersion): array {
            $setting = $this->setting(true);
            if ((int) $setting['version'] !== $expectedVersion) {
                return $this->conflict((int) $setting['version']);
            }
            if ((int) $setting['trash_retention_days'] === $days) {
                return ['success' => true, 'version' => $expectedVersion, 'changed' => false];
            }
            $stmt = $this->pdo->prepare(
                'UPDATE file_space_settings SET trash_retention_days=:days,
                    version=version+1,updated_by=:actor WHERE space_id=:space'
            );
            $stmt->execute([
                ':days' => $days, ':actor' => $actorId, ':space' => (int) $setting['space_id'],
            ]);
            $this->audit($actorId, 'retention.set', ['days' => $days]);
            return ['success' => true, 'version' => $expectedVersion + 1, 'changed' => true];
        });
    }

    /** @return array<string,mixed> */
    private function setting(bool $lock): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.space_id,s.trash_retention_days,s.version
             FROM file_space_settings s JOIN file_spaces f ON f.id=s.space_id
             WHERE f.uid=:uid LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([':uid' => 'global']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Не найдена политика хранения общего файлового пространства');
        }
        return $row;
    }

    /** @return array{success:bool,error:string,message:string,current_version:int} */
    private function conflict(int $current): array
    {
        return [
            'success' => false, 'error' => 'version_conflict',
            'message' => 'Настройки изменены другим администратором. Обновите страницу.',
            'current_version' => $current,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @param \Closure():array<string,mixed> $work
     * @return array<string,mixed>
     */
    private function command(
        int $actorId, string $commandId, string $action, string $entity,
        array $data, \Closure $work
    ): array {
        $this->requireAdmin($actorId);
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Административная команда требует отдельную транзакцию');
        }
        $hash = $this->commands->requestHash($data);
        $this->pdo->beginTransaction();
        try {
            $receipt = $this->commands->acquire(
                $this->pdo, 'user:' . $actorId, $commandId,
                'files', 'files.admin.' . $action, 'file_admin', $entity, $hash
            );
            if (!$receipt['owner']) {
                $result = $this->commands->storedResponse($receipt['record']);
                $this->pdo->commit();
                return $result;
            }
            $result = $work();
            $version = isset($result['version']) ? (int) $result['version'] : null;
            $status = ($result['error'] ?? '') === 'version_conflict'
                ? MutationCommandStore::STATUS_CONFLICT : MutationCommandStore::STATUS_APPLIED;
            $result['replayed'] = false;
            $this->commands->complete(
                $this->pdo, (int) $receipt['record']['id'],
                $receipt['token'], $status, $result, $version
            );
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function requireAdmin(int $actorId): void
    {
        if (!$this->access->actor($actorId)['is_admin']) {
            throw new DomainException('Необходимы административные полномочия', 403);
        }
    }

    /** @param array<string,mixed> $data */
    private function audit(int $actorId, string $event, array $data): void
    {
        $space = $this->access->space();
        $stmt = $this->pdo->prepare(
            'INSERT INTO file_space_events (space_id,actor_user_id,event_code,details_json)
             VALUES (:space,:actor,:code,:details)'
        );
        $stmt->execute([
            ':space' => (int) $space['id'], ':actor' => $actorId,
            ':code' => 'admin.' . $event,
            ':details' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
