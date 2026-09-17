<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * Shared task boards are deliberately separated from legacy personal tasks.
 * Personal tasks keep their established data model, while this service owns
 * collaborative boards, resource-level ACL and assignments.
 */
final class TaskBoardService
{
    private static bool $schemaReady = false;

    private const STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];
    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public function __construct(
        private ?DatabaseManager $db = null,
        private ?RolePolicyService $policies = null
    ) {
        $this->db ??= DatabaseManager::getInstance();
        $this->policies ??= new RolePolicyService($this->db);
    }

    /**
     * Compatibility fallback for beta upgrades: shared-board tables are all
     * additive, so CREATE TABLE IF NOT EXISTS is safe. The same schema also
     * exists as SQL source of truth for fresh installs/migrations.
     */
    public function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $statements = [
            "CREATE TABLE IF NOT EXISTS task_boards (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                uid CHAR(32) NOT NULL,
                owner_user_id INT NOT NULL,
                name VARCHAR(160) NOT NULL,
                audience ENUM('members','all_active') NOT NULL DEFAULT 'members',
                is_archived TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_task_boards_uid (uid),
                KEY idx_task_boards_owner (owner_user_id,is_archived,updated_at),
                KEY idx_task_boards_audience (audience,is_archived,updated_at),
                CONSTRAINT fk_task_boards_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS task_board_members (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                board_id BIGINT UNSIGNED NOT NULL,
                user_id INT NOT NULL,
                role ENUM('manager','member','viewer') NOT NULL DEFAULT 'member',
                joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_task_board_member (board_id,user_id),
                KEY idx_task_board_members_user (user_id,board_id),
                CONSTRAINT fk_task_board_members_board FOREIGN KEY (board_id) REFERENCES task_boards(id) ON DELETE CASCADE,
                CONSTRAINT fk_task_board_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS task_board_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                uid CHAR(32) NOT NULL,
                board_id BIGINT UNSIGNED NOT NULL,
                creator_user_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT DEFAULT NULL,
                status ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
                priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
                due_date DATETIME DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                is_deleted TINYINT(1) NOT NULL DEFAULT 0,
                deleted_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_task_board_items_uid (uid),
                KEY idx_task_board_items_board (board_id,is_deleted,status,updated_at),
                KEY idx_task_board_items_creator (creator_user_id,is_deleted),
                CONSTRAINT fk_task_board_items_board FOREIGN KEY (board_id) REFERENCES task_boards(id) ON DELETE CASCADE,
                CONSTRAINT fk_task_board_items_creator FOREIGN KEY (creator_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS task_board_assignees (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                task_id BIGINT UNSIGNED NOT NULL,
                user_id INT NOT NULL,
                assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_task_board_assignee (task_id,user_id),
                KEY idx_task_board_assignees_user (user_id,task_id),
                CONSTRAINT fk_task_board_assignees_task FOREIGN KEY (task_id) REFERENCES task_board_items(id) ON DELETE CASCADE,
                CONSTRAINT fk_task_board_assignees_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($statements as $sql) {
            $this->db->execute($sql);
        }
        self::$schemaReady = true;
    }

    /** @return list<array<string,mixed>> */
    public function listBoards(int $userId): array
    {
        $this->ensureSchema();
        $this->assertActiveUser($userId);

        return $this->db->fetchAll(
            "SELECT DISTINCT
                b.id,b.uid,b.owner_user_id,b.name,b.audience,b.created_at,b.updated_at,
                owner.username AS owner_username,
                CASE
                    WHEN b.owner_user_id = :user_id_owner THEN 'owner'
                    WHEN bm.role IS NOT NULL THEN bm.role
                    WHEN b.audience = 'all_active' THEN 'member'
                    ELSE NULL
                END AS access_role,
                CASE
                    WHEN b.audience = 'all_active' THEN (
                        SELECT COUNT(*) FROM users au WHERE au.is_active = 1 AND au.account_status = 'active'
                    )
                    ELSE (
                        SELECT COUNT(*) FROM task_board_members m WHERE m.board_id = b.id
                    )
                END AS member_count,
                (SELECT COUNT(*) FROM task_board_items i WHERE i.board_id = b.id AND i.is_deleted = 0) AS task_count
             FROM task_boards b
             INNER JOIN users owner ON owner.id = b.owner_user_id
             LEFT JOIN task_board_members bm ON bm.board_id = b.id AND bm.user_id = :user_id_member
             WHERE b.is_archived = 0
               AND (b.owner_user_id = :user_id_filter OR bm.user_id IS NOT NULL OR b.audience = 'all_active')
             ORDER BY b.updated_at DESC,b.id DESC",
            [
                ':user_id_owner' => $userId,
                ':user_id_member' => $userId,
                ':user_id_filter' => $userId,
            ]
        );
    }

    /** @return array<string,mixed> */
    public function boardForUser(int $userId, string $boardUid): array
    {
        $this->ensureSchema();
        $this->assertActiveUser($userId);
        $boardUid = trim($boardUid);
        if ($boardUid === '') {
            throw new InvalidArgumentException('Доска не указана');
        }

        $row = $this->db->fetchOne(
            "SELECT
                b.id,b.uid,b.owner_user_id,b.name,b.audience,b.created_at,b.updated_at,
                owner.username AS owner_username,
                CASE
                    WHEN b.owner_user_id = :user_id_owner THEN 'owner'
                    WHEN bm.role IS NOT NULL THEN bm.role
                    WHEN b.audience = 'all_active' THEN 'member'
                    ELSE NULL
                END AS access_role
             FROM task_boards b
             INNER JOIN users owner ON owner.id = b.owner_user_id
             LEFT JOIN task_board_members bm ON bm.board_id = b.id AND bm.user_id = :user_id_member
             WHERE b.uid = :uid
               AND b.is_archived = 0
               AND (b.owner_user_id = :user_id_filter OR bm.user_id IS NOT NULL OR b.audience = 'all_active')
             LIMIT 1",
            [
                ':user_id_owner' => $userId,
                ':user_id_member' => $userId,
                ':user_id_filter' => $userId,
                ':uid' => $boardUid,
            ]
        );
        if (!$row || empty($row['access_role'])) {
            throw new DomainException('Доска не найдена или недоступна', 404);
        }

        $row['can_write'] = in_array((string) $row['access_role'], ['owner', 'manager', 'member'], true);
        $row['can_manage'] = in_array((string) $row['access_role'], ['owner', 'manager'], true);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function activeUsers(): array
    {
        $this->ensureSchema();
        return $this->db->fetchAll(
            "SELECT id,uid,username,firstname,lastname
             FROM users
             WHERE is_active = 1 AND account_status = 'active'
             ORDER BY firstname ASC,lastname ASC,username ASC,id ASC"
        );
    }

    /** @return list<array<string,mixed>> */
    public function members(int $userId, string $boardUid): array
    {
        $board = $this->boardForUser($userId, $boardUid);
        if ((string) $board['audience'] === 'all_active') {
            $users = $this->activeUsers();
            foreach ($users as &$user) {
                $user['board_role'] = (int) $user['id'] === (int) $board['owner_user_id'] ? 'owner' : 'member';
            }
            unset($user);
            return $users;
        }

        return $this->db->fetchAll(
            "SELECT u.id,u.uid,u.username,u.firstname,u.lastname,
                    CASE WHEN u.id = :owner_id THEN 'owner' ELSE bm.role END AS board_role
             FROM task_board_members bm
             INNER JOIN users u ON u.id = bm.user_id
             WHERE bm.board_id = :board_id
               AND u.is_active = 1
               AND u.account_status = 'active'
             ORDER BY (u.id = :owner_id_order) DESC,u.firstname,u.lastname,u.username",
            [
                ':owner_id' => (int) $board['owner_user_id'],
                ':board_id' => (int) $board['id'],
                ':owner_id_order' => (int) $board['owner_user_id'],
            ]
        );
    }

    public function createBoard(int $userId, string $name, string $audience, array $memberIds): string
    {
        $this->ensureSchema();
        $this->assertActiveUser($userId);
        $name = $this->name($name);
        $audience = in_array($audience, ['members', 'all_active'], true) ? $audience : 'members';

        if (!(bool) $this->policies->effectiveValue($userId, 'tasks', 'can_create_shared_boards')) {
            throw new DomainException('Создание общих досок отключено для вашей роли', 403);
        }

        $ownedLimit = (int) $this->policies->effectiveValue($userId, 'tasks', 'max_owned_boards');
        if ($ownedLimit > 0) {
            $owned = (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM task_boards WHERE owner_user_id = :user_id AND is_archived = 0',
                [':user_id' => $userId]
            );
            if ($owned >= $ownedLimit) {
                throw new DomainException('Достигнут лимит собственных общих досок', 403);
            }
        }

        $members = $audience === 'members' ? $this->normalizeMemberIds($memberIds, $userId) : [];
        $maxMembers = (int) $this->policies->effectiveValue($userId, 'tasks', 'max_board_members');
        $effectiveMemberCount = $audience === 'all_active'
            ? (int) $this->db->fetchValue("SELECT COUNT(*) FROM users WHERE is_active = 1 AND account_status = 'active'")
            : count($members) + 1;
        if ($maxMembers > 0 && $effectiveMemberCount > $maxMembers) {
            throw new DomainException('Количество участников превышает лимит роли', 403);
        }

        $uid = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $this->db->execute(
                'INSERT INTO task_boards (uid,owner_user_id,name,audience,is_archived,created_at,updated_at)
                 VALUES (:uid,:owner_user_id,:name,:audience,0,:created_at,:updated_at)',
                [
                    ':uid' => $uid,
                    ':owner_user_id' => $userId,
                    ':name' => $name,
                    ':audience' => $audience,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]
            );
            $boardId = (int) $this->db->getPdo()->lastInsertId();
            $this->db->execute(
                'INSERT INTO task_board_members (board_id,user_id,role,joined_at)
                 VALUES (:board_id,:user_id,"manager",:joined_at)',
                [':board_id' => $boardId, ':user_id' => $userId, ':joined_at' => $now]
            );
            foreach ($members as $memberId) {
                $this->db->execute(
                    'INSERT INTO task_board_members (board_id,user_id,role,joined_at)
                     VALUES (:board_id,:user_id,"member",:joined_at)',
                    [':board_id' => $boardId, ':user_id' => $memberId, ':joined_at' => $now]
                );
            }
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }

        return $uid;
    }

    public function replaceMembers(int $userId, string $boardUid, array $memberIds): void
    {
        $board = $this->boardForUser($userId, $boardUid);
        if (!(bool) $board['can_manage']) {
            throw new DomainException('Недостаточно прав для управления участниками', 403);
        }
        if ((string) $board['audience'] !== 'members') {
            throw new InvalidArgumentException('Для доски «Все пользователи» состав определяется автоматически');
        }

        $members = $this->normalizeMemberIds($memberIds, (int) $board['owner_user_id']);
        $maxMembers = (int) $this->policies->effectiveValue($userId, 'tasks', 'max_board_members');
        if ($maxMembers > 0 && count($members) + 1 > $maxMembers) {
            throw new DomainException('Количество участников превышает лимит роли', 403);
        }

        $this->db->beginTransaction();
        try {
            $this->db->execute(
                'DELETE FROM task_board_members WHERE board_id = :board_id AND user_id <> :owner_id',
                [':board_id' => (int) $board['id'], ':owner_id' => (int) $board['owner_user_id']]
            );
            foreach ($members as $memberId) {
                $this->db->execute(
                    'INSERT INTO task_board_members (board_id,user_id,role,joined_at)
                     VALUES (:board_id,:user_id,"member",:joined_at)',
                    [
                        ':board_id' => (int) $board['id'],
                        ':user_id' => $memberId,
                        ':joined_at' => date('Y-m-d H:i:s'),
                    ]
                );
            }
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function listTasks(int $userId, string $boardUid): array
    {
        $board = $this->boardForUser($userId, $boardUid);
        $rows = $this->db->fetchAll(
            "SELECT i.*,creator.username AS creator_username
             FROM task_board_items i
             INNER JOIN users creator ON creator.id = i.creator_user_id
             WHERE i.board_id = :board_id AND i.is_deleted = 0
             ORDER BY FIELD(i.status,'pending','in_progress','completed','cancelled'),i.updated_at DESC,i.id DESC",
            [':board_id' => (int) $board['id']]
        );

        if ($rows === []) {
            return [];
        }
        $ids = array_values(array_map(static fn(array $row): int => (int) $row['id'], $rows));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $assignees = $this->db->getPdo()->prepare(
            "SELECT a.task_id,u.id,u.uid,u.username,u.firstname,u.lastname
             FROM task_board_assignees a
             INNER JOIN users u ON u.id = a.user_id
             WHERE a.task_id IN ({$placeholders})
             ORDER BY u.firstname,u.lastname,u.username"
        );
        $assignees->execute($ids);
        $byTask = [];
        while ($row = $assignees->fetch(\PDO::FETCH_ASSOC)) {
            $byTask[(int) $row['task_id']][] = $row;
        }
        foreach ($rows as &$row) {
            $row['assignees'] = $byTask[(int) $row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }

    public function createTask(
        int $userId,
        string $boardUid,
        string $title,
        string $description,
        string $status,
        string $priority,
        ?string $dueDate,
        array $assigneeIds
    ): string {
        $board = $this->boardForUser($userId, $boardUid);
        $this->requireWrite($board);
        $title = $this->title($title);
        $description = $this->description($description);
        $status = $this->status($status);
        $priority = $this->priority($priority);
        $dueDate = $this->dateTimeOrNull($dueDate);
        $assigneeIds = $this->validatedAssignees($userId, $board, $assigneeIds);

        $uid = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $this->db->execute(
                'INSERT INTO task_board_items (
                    uid,board_id,creator_user_id,title,description,status,priority,due_date,completed_at,
                    is_deleted,deleted_at,created_at,updated_at
                 ) VALUES (
                    :uid,:board_id,:creator_user_id,:title,:description,:status,:priority,:due_date,:completed_at,
                    0,NULL,:created_at,:updated_at
                 )',
                [
                    ':uid' => $uid,
                    ':board_id' => (int) $board['id'],
                    ':creator_user_id' => $userId,
                    ':title' => $title,
                    ':description' => $description,
                    ':status' => $status,
                    ':priority' => $priority,
                    ':due_date' => $dueDate,
                    ':completed_at' => $status === 'completed' ? $now : null,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]
            );
            $taskId = (int) $this->db->getPdo()->lastInsertId();
            $this->replaceTaskAssigneesById($taskId, $assigneeIds);
            $this->touchBoard((int) $board['id']);
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
        return $uid;
    }

    /** @param array<string,mixed> $changes */
    public function updateTask(int $userId, string $taskUid, array $changes): array
    {
        $this->ensureSchema();
        $task = $this->taskForUser($userId, $taskUid);
        $board = $this->boardForUser($userId, (string) $task['board_uid']);
        $this->requireWrite($board);

        $set = [];
        $params = [':id' => (int) $task['id']];
        if (array_key_exists('title', $changes)) {
            $set['title'] = $this->title((string) $changes['title']);
        }
        if (array_key_exists('description', $changes)) {
            $set['description'] = $this->description((string) $changes['description']);
        }
        if (array_key_exists('status', $changes)) {
            $set['status'] = $this->status((string) $changes['status']);
            $set['completed_at'] = $set['status'] === 'completed' ? date('Y-m-d H:i:s') : null;
        }
        if (array_key_exists('priority', $changes)) {
            $set['priority'] = $this->priority((string) $changes['priority']);
        }
        if (array_key_exists('due_date', $changes)) {
            $set['due_date'] = $this->dateTimeOrNull($changes['due_date'] === null ? null : (string) $changes['due_date']);
        }

        $assigneeIds = null;
        if (array_key_exists('assignee_ids', $changes)) {
            $assigneeIds = $this->validatedAssignees(
                $userId,
                $board,
                is_array($changes['assignee_ids']) ? $changes['assignee_ids'] : []
            );
        }

        if ($set === [] && $assigneeIds === null) {
            return $task;
        }

        $this->db->beginTransaction();
        try {
            if ($set !== []) {
                $set['updated_at'] = date('Y-m-d H:i:s');
                $sqlSet = [];
                foreach ($set as $column => $value) {
                    $key = ':' . $column;
                    $sqlSet[] = $column . ' = ' . $key;
                    $params[$key] = $value;
                }
                $this->db->execute(
                    'UPDATE task_board_items SET ' . implode(', ', $sqlSet) . ' WHERE id = :id AND is_deleted = 0',
                    $params
                );
            }
            if ($assigneeIds !== null) {
                $this->replaceTaskAssigneesById((int) $task['id'], $assigneeIds);
            }
            $this->touchBoard((int) $board['id']);
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }

        return $this->taskForUser($userId, $taskUid);
    }

    public function deleteTask(int $userId, string $taskUid): void
    {
        $task = $this->taskForUser($userId, $taskUid);
        $board = $this->boardForUser($userId, (string) $task['board_uid']);
        $this->requireWrite($board);
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'UPDATE task_board_items SET is_deleted = 1,deleted_at = :now,updated_at = :now WHERE id = :id AND is_deleted = 0',
            [':now' => $now, ':id' => (int) $task['id']]
        );
        $this->touchBoard((int) $board['id']);
    }

    /** @return array<string,mixed> */
    public function policySnapshot(int $userId): array
    {
        return [
            'can_create_shared_boards' => (bool) $this->policies->effectiveValue($userId, 'tasks', 'can_create_shared_boards'),
            'can_assign_tasks' => (bool) $this->policies->effectiveValue($userId, 'tasks', 'can_assign_tasks'),
            'max_owned_boards' => (int) $this->policies->effectiveValue($userId, 'tasks', 'max_owned_boards'),
            'max_board_members' => (int) $this->policies->effectiveValue($userId, 'tasks', 'max_board_members'),
        ];
    }

    /** @return array<string,mixed> */
    private function taskForUser(int $userId, string $taskUid): array
    {
        $this->ensureSchema();
        $row = $this->db->fetchOne(
            "SELECT i.*,b.uid AS board_uid
             FROM task_board_items i
             INNER JOIN task_boards b ON b.id = i.board_id AND b.is_archived = 0
             LEFT JOIN task_board_members bm ON bm.board_id = b.id AND bm.user_id = :user_id_member
             INNER JOIN users me ON me.id = :user_id_active AND me.is_active = 1 AND me.account_status = 'active'
             WHERE i.uid = :task_uid AND i.is_deleted = 0
               AND (b.owner_user_id = :user_id_owner OR bm.user_id IS NOT NULL OR b.audience = 'all_active')
             LIMIT 1",
            [
                ':user_id_member' => $userId,
                ':user_id_active' => $userId,
                ':task_uid' => trim($taskUid),
                ':user_id_owner' => $userId,
            ]
        );
        if (!$row) {
            throw new DomainException('Задача общей доски не найдена или недоступна', 404);
        }
        return $row;
    }

    /** @param array<string,mixed> $board */
    private function requireWrite(array $board): void
    {
        if (!(bool) ($board['can_write'] ?? false)) {
            throw new DomainException('Доска доступна только для просмотра', 403);
        }
    }

    /** @param array<string,mixed> $board @return list<int> */
    private function validatedAssignees(int $actorId, array $board, array $assigneeIds): array
    {
        $ids = $this->normalizeIds($assigneeIds);
        if ($ids === []) {
            return [];
        }
        if (!(bool) $this->policies->effectiveValue($actorId, 'tasks', 'can_assign_tasks')) {
            throw new DomainException('Назначение задач участникам отключено для вашей роли', 403);
        }

        $allowed = [];
        if ((string) $board['audience'] === 'all_active') {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->getPdo()->prepare(
                "SELECT id FROM users WHERE id IN ({$placeholders}) AND is_active = 1 AND account_status = 'active'"
            );
            $stmt->execute($ids);
            $allowed = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->getPdo()->prepare(
                "SELECT bm.user_id
                 FROM task_board_members bm
                 INNER JOIN users u ON u.id = bm.user_id AND u.is_active = 1 AND u.account_status = 'active'
                 WHERE bm.board_id = ? AND bm.user_id IN ({$placeholders})"
            );
            $stmt->execute([(int) $board['id'], ...$ids]);
            $allowed = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }
        sort($allowed);
        $expected = $ids;
        sort($expected);
        if ($allowed !== $expected) {
            throw new InvalidArgumentException('Назначать задачу можно только доступным участникам доски');
        }
        return $ids;
    }

    /** @return list<int> */
    private function normalizeMemberIds(array $memberIds, int $ownerId): array
    {
        $ids = array_values(array_filter($this->normalizeIds($memberIds), static fn(int $id): bool => $id !== $ownerId));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->getPdo()->prepare(
            "SELECT id FROM users WHERE id IN ({$placeholders}) AND is_active = 1 AND account_status = 'active'"
        );
        $stmt->execute($ids);
        $valid = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        sort($valid);
        sort($ids);
        if ($valid !== $ids) {
            throw new InvalidArgumentException('В состав доски можно добавить только активных пользователей');
        }
        return $ids;
    }

    /** @return list<int> */
    private function normalizeIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id !== false && (int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }
        return array_keys($ids);
    }

    /** @param list<int> $assigneeIds */
    private function replaceTaskAssigneesById(int $taskId, array $assigneeIds): void
    {
        $this->db->execute('DELETE FROM task_board_assignees WHERE task_id = :task_id', [':task_id' => $taskId]);
        foreach ($assigneeIds as $userId) {
            $this->db->execute(
                'INSERT INTO task_board_assignees (task_id,user_id,assigned_at) VALUES (:task_id,:user_id,:assigned_at)',
                [':task_id' => $taskId, ':user_id' => $userId, ':assigned_at' => date('Y-m-d H:i:s')]
            );
        }
    }

    private function touchBoard(int $boardId): void
    {
        $this->db->execute(
            'UPDATE task_boards SET updated_at = :updated_at WHERE id = :id',
            [':updated_at' => date('Y-m-d H:i:s'), ':id' => $boardId]
        );
    }

    private function assertActiveUser(int $userId): void
    {
        if ($userId <= 0) {
            throw new DomainException('Требуется авторизация', 401);
        }
        $active = $this->db->fetchValue(
            "SELECT id FROM users WHERE id = :id AND is_active = 1 AND account_status = 'active' LIMIT 1",
            [':id' => $userId]
        );
        if (!$active) {
            throw new DomainException('Пользователь недоступен', 403);
        }
    }

    private function name(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '' || mb_strlen($value) > 160) {
            throw new InvalidArgumentException('Название доски должно содержать от 1 до 160 символов');
        }
        return $value;
    }

    private function title(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '' || mb_strlen($value) > 255) {
            throw new InvalidArgumentException('Название задачи должно содержать от 1 до 255 символов');
        }
        return $value;
    }

    private function description(string $value): string
    {
        if (mb_strlen($value) > 10000) {
            throw new InvalidArgumentException('Описание задачи слишком длинное');
        }
        return trim($value);
    }

    private function status(string $value): string
    {
        return in_array($value, self::STATUSES, true)
            ? $value
            : throw new InvalidArgumentException('Некорректный статус задачи');
    }

    private function priority(string $value): string
    {
        return in_array($value, self::PRIORITIES, true)
            ? $value
            : throw new InvalidArgumentException('Некорректный приоритет задачи');
    }

    private function dateTimeOrNull(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        if (!$date) {
            throw new InvalidArgumentException('Некорректная дата задачи');
        }
        return $date->format('Y-m-d H:i:s');
    }
}
