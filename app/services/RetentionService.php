<?php

declare(strict_types=1);

namespace App\Services;

require_once dirname(__DIR__, 2) . '/core/SecurityEventLog.php';

use Core\DatabaseManager;
use Core\SecurityEventLog;
use RuntimeException;
use Throwable;

final class RetentionService
{
    private const MAX_LIMIT = 500;

    /** @var array<string,bool> */
    private array $tableCache = [];
    private string $appRoot;
    private string $privateRoot;

    public function __construct(private ?DatabaseManager $db = null, ?string $appRoot = null)
    {
        $this->db ??= DatabaseManager::getInstance();
        $resolved = realpath($appRoot ?? (defined('SITEPATH') ? SITEPATH : dirname(__DIR__, 2)));
        if (!is_string($resolved) || !is_dir($resolved)) {
            throw new RuntimeException('Application root cannot be resolved for retention service');
        }
        $this->appRoot = rtrim($resolved, DIRECTORY_SEPARATOR);

        $private = trim((string) (getenv('PRIVATE_STORAGE_PATH') ?: ''));
        if ($private === '' || !$this->isAbsolutePath($private)) {
            throw new RuntimeException('PRIVATE_STORAGE_PATH must be an absolute path for retention purge');
        }
        $privateReal = realpath($private);
        if (!is_string($privateReal) || !is_dir($privateReal) || !is_writable($privateReal)) {
            throw new RuntimeException('PRIVATE_STORAGE_PATH must exist and be writable for retention purge');
        }
        if ($this->isPathWithin($privateReal, $this->appRoot)) {
            throw new RuntimeException('PRIVATE_STORAGE_PATH must be outside the application tree');
        }
        $this->privateRoot = rtrim($privateReal, DIRECTORY_SEPARATOR);
    }

    /** @return array<string,mixed> */
    public function preview(int $softDeleteDays, int $accountDays, int $limit = 100): array
    {
        [$softDeleteDays, $accountDays, $limit] = $this->normalize($softDeleteDays, $accountDays, $limit);
        $softCutoff = $this->cutoff($softDeleteDays);
        $accountCutoff = $this->cutoff($accountDays);
        $accountDueAt = gmdate('Y-m-d H:i:s');

        $soft = [];
        foreach ($this->softDeleteDefinitions() as $name => $definition) {
            if (!$this->hasTable($definition['table'])) {
                continue;
            }
            if ($name === 'notes' && $this->hasTable('note_attachments')) {
                $soft[$name] = min(
                    $limit,
                    (int) $this->db->fetchValue(
                        'SELECT COUNT(*) FROM notes n '
                        . 'WHERE n.is_deleted=1 AND n.deleted_at IS NOT NULL AND n.deleted_at < :cutoff '
                        . 'AND NOT EXISTS (SELECT 1 FROM note_attachments a WHERE a.note_id=n.id '
                        . 'AND (a.is_deleted=0 OR a.deleted_at IS NULL OR a.deleted_at >= :attachment_cutoff))',
                        [':cutoff' => $softCutoff, ':attachment_cutoff' => $softCutoff]
                    )
                );
                continue;
            }
            $soft[$name] = min(
                $limit,
                (int) $this->db->fetchValue(
                    'SELECT COUNT(*) FROM ' . $definition['table'] . ' WHERE ' . $definition['where'],
                    [':cutoff' => $softCutoff]
                )
            );
        }

        $accounts = ['eligible' => 0, 'blocked' => 0];
        if ($this->hasTable('users')) {
            $rows = $this->accountCandidates($accountDueAt, $limit);
            foreach ($rows as $row) {
                if ($this->accountBlockReason((int) $row['id']) !== null) {
                    $accounts['blocked']++;
                } else {
                    $accounts['eligible']++;
                }
            }
        }

        return [
            'status' => 'preview',
            'soft_delete_days' => $softDeleteDays,
            'account_days' => $accountDays,
            'soft_delete_cutoff' => $softCutoff,
            'account_cutoff' => $accountCutoff,
            'account_due_at' => $accountDueAt,
            'limit' => $limit,
            'soft_delete_candidates' => $soft,
            'account_candidates' => $accounts,
        ];
    }

    /** @return array<string,mixed> */
    public function apply(int $softDeleteDays, int $accountDays, int $limit = 100): array
    {
        [$softDeleteDays, $accountDays, $limit] = $this->normalize($softDeleteDays, $accountDays, $limit);
        $softCutoff = $this->cutoff($softDeleteDays);
        $accountCutoff = $this->cutoff($accountDays);
        $accountDueAt = gmdate('Y-m-d H:i:s');

        $result = [
            'status' => 'applied',
            'soft_delete_days' => $softDeleteDays,
            'account_days' => $accountDays,
            'soft_delete_cutoff' => $softCutoff,
            'account_cutoff' => $accountCutoff,
            'account_due_at' => $accountDueAt,
            'purged' => [],
            'files_deleted' => 0,
            'files_missing' => 0,
            'files_blocked' => 0,
            'files_failed' => 0,
            'accounts_purged' => 0,
            'accounts_blocked' => 0,
            'accounts_failed' => 0,
        ];

        $this->purgeNoteAttachments($softCutoff, $limit, $result);
        $this->purgeNotes($softCutoff, $limit, $result);
        $this->purgeUserFiles($softCutoff, $limit, $result);
        $this->purgeMessengerAttachments($softCutoff, $limit, $result);
        $this->purgeMessages($softCutoff, $limit, $result);
        $this->purgeSimpleRows('tasks', 'tasks', 'is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff', $softCutoff, $limit, $result);
        $this->purgeSimpleRows('task_board_items', 'task_board_items', 'is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff', $softCutoff, $limit, $result);
        $this->purgeSimpleRows('task_categories', 'task_categories', 'is_deleted = 1 AND updated_at < :cutoff', $softCutoff, $limit, $result);
        $this->purgeAccounts($accountDueAt, $limit, $result);

        SecurityEventLog::emit(
            'retention.purge_completed',
            ($result['files_failed'] > 0 || $result['files_blocked'] > 0 || $result['accounts_failed'] > 0) ? 'warning' : 'info',
            'retention',
            'cli',
            null,
            [
                'purged' => $result['purged'],
                'files_deleted' => $result['files_deleted'],
                'files_failed' => $result['files_failed'],
                'files_blocked' => $result['files_blocked'],
                'accounts_purged' => $result['accounts_purged'],
                'accounts_blocked' => $result['accounts_blocked'],
                'accounts_failed' => $result['accounts_failed'],
            ]
        );

        return $result;
    }

    /** @param array<string,mixed> $result */
    private function purgeNoteAttachments(string $cutoff, int $limit, array &$result): void
    {
        if (!$this->hasTable('note_attachments')) {
            return;
        }
        $rows = $this->db->fetchAll(
            'SELECT id,file_path FROM note_attachments '
            . 'WHERE is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff '
            . 'ORDER BY id ASC LIMIT ' . $limit,
            [':cutoff' => $cutoff]
        );
        $purged = 0;
        foreach ($rows as $row) {
            if (!$this->removePaths([(string) ($row['file_path'] ?? '')], $result)) {
                continue;
            }
            $purged += $this->db->execute(
                'DELETE FROM note_attachments WHERE id = :id AND is_deleted = 1 AND deleted_at < :cutoff',
                [':id' => (int) $row['id'], ':cutoff' => $cutoff]
            );
        }
        $this->addPurged($result, 'note_attachments', $purged);
    }

    /** @param array<string,mixed> $result */
    private function purgeNotes(string $cutoff, int $limit, array &$result): void
    {
        if (!$this->hasTable('notes')) {
            return;
        }
        $rows = $this->db->fetchAll(
            'SELECT n.id FROM notes n WHERE n.is_deleted = 1 AND n.deleted_at IS NOT NULL AND n.deleted_at < :cutoff '
            . ($this->hasTable('note_attachments')
                ? 'AND NOT EXISTS (SELECT 1 FROM note_attachments a WHERE a.note_id=n.id '
                    . 'AND (a.is_deleted=0 OR a.deleted_at IS NULL OR a.deleted_at >= :attachment_cutoff)) '
                : '')
            . 'ORDER BY n.id ASC LIMIT ' . $limit,
            $this->hasTable('note_attachments')
                ? [':cutoff' => $cutoff, ':attachment_cutoff' => $cutoff]
                : [':cutoff' => $cutoff]
        );
        $purged = 0;
        foreach ($rows as $row) {
            $noteId = (int) $row['id'];
            $paths = [];
            if ($this->hasTable('note_attachments')) {
                $attachments = $this->db->fetchAll(
                    'SELECT file_path FROM note_attachments WHERE note_id = :note_id',
                    [':note_id' => $noteId]
                );
                foreach ($attachments as $attachment) {
                    $paths[] = (string) ($attachment['file_path'] ?? '');
                }
            }
            if (!$this->removePaths($paths, $result)) {
                continue;
            }
            $purged += $this->db->execute(
                'DELETE FROM notes WHERE id = :id AND is_deleted = 1 AND deleted_at < :cutoff',
                [':id' => $noteId, ':cutoff' => $cutoff]
            );
        }
        $this->addPurged($result, 'notes', $purged);
    }

    /** @param array<string,mixed> $result */
    private function purgeUserFiles(string $cutoff, int $limit, array &$result): void
    {
        if (!$this->hasTable('user_files')) {
            return;
        }
        $rows = $this->db->fetchAll(
            "SELECT id,path FROM user_files WHERE is_deleted = 1 AND updated_at < :cutoff AND type <> 'folder' "
            . 'ORDER BY id ASC LIMIT ' . $limit,
            [':cutoff' => $cutoff]
        );
        $purged = 0;
        foreach ($rows as $row) {
            if (!$this->removePaths([(string) ($row['path'] ?? '')], $result)) {
                continue;
            }
            $purged += $this->db->execute(
                "DELETE FROM user_files WHERE id = :id AND is_deleted = 1 AND updated_at < :cutoff AND type <> 'folder'",
                [':id' => (int) $row['id'], ':cutoff' => $cutoff]
            );
        }

        // Delete only empty folders so ON DELETE CASCADE cannot pull a newer child
        // across the retention boundary.
        $folders = $this->db->fetchAll(
            "SELECT uf.id FROM user_files uf "
            . "WHERE uf.is_deleted = 1 AND uf.updated_at < :cutoff AND uf.type = 'folder' "
            . 'AND NOT EXISTS (SELECT 1 FROM user_files child WHERE child.parent_id = uf.id) '
            . 'ORDER BY uf.id ASC LIMIT ' . $limit,
            [':cutoff' => $cutoff]
        );
        foreach ($folders as $folder) {
            $purged += $this->db->execute(
                "DELETE FROM user_files WHERE id = :id AND is_deleted = 1 AND updated_at < :cutoff AND type = 'folder'",
                [':id' => (int) $folder['id'], ':cutoff' => $cutoff]
            );
        }
        $this->addPurged($result, 'user_files', $purged);
    }

    /** @param array<string,mixed> $result */
    private function purgeMessengerAttachments(string $cutoff, int $limit, array &$result): void
    {
        if (!$this->hasTable('messenger_attachments')) {
            return;
        }
        $rows = $this->db->fetchAll(
            'SELECT id,stored_path FROM messenger_attachments '
            . 'WHERE is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff '
            . 'ORDER BY id ASC LIMIT ' . $limit,
            [':cutoff' => $cutoff]
        );
        $purged = 0;
        foreach ($rows as $row) {
            if (!$this->removePaths([(string) ($row['stored_path'] ?? '')], $result)) {
                continue;
            }
            $purged += $this->db->execute(
                'DELETE FROM messenger_attachments WHERE id = :id AND is_deleted = 1 AND deleted_at < :cutoff',
                [':id' => (int) $row['id'], ':cutoff' => $cutoff]
            );
        }
        $this->addPurged($result, 'messenger_attachments', $purged);
    }

    /** @param array<string,mixed> $result */
    private function purgeMessages(string $cutoff, int $limit, array &$result): void
    {
        if (!$this->hasTable('messages')) {
            return;
        }
        $rows = $this->db->fetchAll(
            'SELECT id FROM messages WHERE is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff '
            . 'ORDER BY id ASC LIMIT ' . $limit,
            [':cutoff' => $cutoff]
        );
        $purged = 0;
        foreach ($rows as $row) {
            $messageId = (int) $row['id'];
            $paths = [];
            if ($this->hasTable('messenger_attachments')) {
                $attachments = $this->db->fetchAll(
                    'SELECT stored_path FROM messenger_attachments WHERE message_id = :message_id',
                    [':message_id' => $messageId]
                );
                foreach ($attachments as $attachment) {
                    $paths[] = (string) ($attachment['stored_path'] ?? '');
                }
            }
            if (!$this->removePaths($paths, $result)) {
                continue;
            }
            $purged += $this->db->execute(
                'DELETE FROM messages WHERE id = :id AND is_deleted = 1 AND deleted_at < :cutoff',
                [':id' => $messageId, ':cutoff' => $cutoff]
            );
        }
        $this->addPurged($result, 'messages', $purged);
    }

    /** @param array<string,mixed> $result */
    private function purgeSimpleRows(
        string $resultKey,
        string $table,
        string $where,
        string $cutoff,
        int $limit,
        array &$result
    ): void {
        if (!$this->hasTable($table)) {
            return;
        }
        $rows = $this->db->fetchAll(
            'SELECT id FROM ' . $table . ' WHERE ' . $where . ' ORDER BY id ASC LIMIT ' . $limit,
            [':cutoff' => $cutoff]
        );
        $purged = 0;
        foreach ($rows as $row) {
            $purged += $this->db->execute(
                'DELETE FROM ' . $table . ' WHERE id = :id AND ' . $where,
                [':id' => (int) $row['id'], ':cutoff' => $cutoff]
            );
        }
        $this->addPurged($result, $resultKey, $purged);
    }

    /**
     * Очищает только аккаунты, явно помеченные на удаление и уже достигшие purge_after.
     *
     * Строка users остаётся как технический tombstone: физический DELETE мог бы
     * каскадно уничтожить сообщения и другие совместные объекты других пользователей.
     * Персональные регистрационные данные при этом удаляются необратимо.
     *
     * @param array<string,mixed> $result
     */
    private function purgeAccounts(string $dueAt, int $limit, array &$result): void
    {
        if (!$this->hasTable('users')) {
            return;
        }

        foreach ($this->accountCandidates($dueAt, $limit) as $user) {
            $userId = (int) $user['id'];
            $reason = $this->accountBlockReason($userId);
            if ($reason !== null) {
                $result['accounts_blocked']++;
                SecurityEventLog::emit(
                    'retention.account_blocked',
                    'warning',
                    'retention',
                    'cli',
                    null,
                    ['user_id' => $userId, 'reason' => $reason]
                );
                continue;
            }

            // Удаляем только отдельный аватар профиля. Пользовательские заметки,
            // сообщения, задачи и совместные вложения не должны исчезать каскадно.
            if (!$this->removePaths($this->identityPaths($userId), $result)) {
                $result['accounts_failed']++;
                continue;
            }

            try {
                $this->db->beginTransaction();
                $locked = $this->db->fetchOne(
                    "SELECT id,uid,purge_after,anonymized_at
                     FROM users
                     WHERE id=:id
                       AND is_active=0
                       AND account_status='inactive'
                       AND deletion_requested_at IS NOT NULL
                       AND purge_after IS NOT NULL
                       AND purge_after <= :due_at
                       AND anonymized_at IS NULL
                     LIMIT 1 FOR UPDATE",
                    [':id' => $userId, ':due_at' => $dueAt]
                );
                if (!$locked) {
                    $this->db->endTransaction(false);
                    continue;
                }

                $uidToken = strtolower(substr(
                    preg_replace('/[^a-f0-9]/i', '', (string) ($locked['uid'] ?? '')) ?: 'user',
                    0,
                    12
                ));
                $username = 'deleted_' . $userId . '_' . $uidToken;
                $email = 'deleted+' . $userId . '+' . $uidToken . '@invalid.workspace.local';
                $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                if (!is_string($passwordHash) || $passwordHash === '') {
                    throw new RuntimeException('Не удалось подготовить tombstone-пароль');
                }

                $now = gmdate('Y-m-d H:i:s');
                $affected = $this->db->execute(
                    "UPDATE users SET
                        username=:username,
                        email=:email,
                        password_hash=:password_hash,
                        firstname='Удалённый',
                        patronymic=NULL,
                        lastname='пользователь',
                        phone=NULL,
                        avatar=NULL,
                        property=:property,
                        role=888,
                        is_active=0,
                        account_status='inactive',
                        purge_after=NULL,
                        anonymized_at=:anonymized_at,
                        totp_enabled=0,
                        totp_secret=NULL,
                        totp_last_counter=NULL,
                        totp_recovery_codes=NULL,
                        totp_confirmed_at=NULL,
                        updated_at=:updated_at
                     WHERE id=:id AND anonymized_at IS NULL",
                    [
                        ':username' => $username,
                        ':email' => $email,
                        ':password_hash' => $passwordHash,
                        ':property' => '{}',
                        ':anonymized_at' => $now,
                        ':updated_at' => $now,
                        ':id' => $userId,
                    ]
                );

                if ($this->hasTable('user_roles')) {
                    $this->db->execute(
                        'DELETE FROM user_roles WHERE user_id=:user_id',
                        [':user_id' => $userId]
                    );
                }
                if ($this->hasTable('user_storage_quotas')) {
                    $this->db->execute(
                        'DELETE FROM user_storage_quotas WHERE user_id=:user_id',
                        [':user_id' => $userId]
                    );
                }

                $this->db->endTransaction(true);
            } catch (Throwable $e) {
                $this->db->endTransaction(false);
                $result['accounts_failed']++;
                SecurityEventLog::emit(
                    'retention.account_purge_failed',
                    'critical',
                    'retention',
                    'cli',
                    null,
                    ['user_id' => $userId, 'error_type' => $e::class]
                );
                continue;
            }

            if ($affected === 1) {
                $result['accounts_purged']++;
                SecurityEventLog::emit(
                    'retention.account_purged',
                    'warning',
                    'retention',
                    'cli',
                    null,
                    ['user_id' => $userId, 'mode' => 'anonymized_tombstone']
                );
                $this->removeEmptyUserStorage($userId);
            }
        }
    }

    /** @return array{accounts_purged:int,accounts_blocked:int,accounts_failed:int,files_deleted:int,files_missing:int,files_blocked:int,files_failed:int} */
    public function purgeScheduledAccounts(int $limit = 100): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $result = [
            'accounts_purged' => 0,
            'accounts_blocked' => 0,
            'accounts_failed' => 0,
            'files_deleted' => 0,
            'files_missing' => 0,
            'files_blocked' => 0,
            'files_failed' => 0,
        ];
        $this->purgeAccounts(gmdate('Y-m-d H:i:s'), $limit, $result);
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function accountCandidates(string $dueAt, int $limit): array
    {
        return $this->db->fetchAll(
            "SELECT id,uid,deletion_requested_at,purge_after,anonymized_at
             FROM users
             WHERE is_active=0
               AND account_status='inactive'
               AND deletion_requested_at IS NOT NULL
               AND purge_after IS NOT NULL
               AND purge_after <= :due_at
               AND anonymized_at IS NULL
             ORDER BY purge_after ASC,id ASC
             LIMIT " . $limit,
            [':due_at' => $dueAt]
        );
    }

    private function accountBlockReason(int $userId): ?string
    {
        if ($this->hasTable('user_roles') && $this->hasTable('role_permissions') && $this->hasTable('permissions')) {
            $admin = $this->db->fetchValue(
                "SELECT 1 FROM user_roles ur "
                . 'JOIN role_permissions rp ON rp.role_id=ur.role_id '
                . 'JOIN permissions p ON p.id=rp.permission_id '
                . "WHERE ur.user_id=:user_id AND p.code LIKE 'admin.%' LIMIT 1",
                [':user_id' => $userId]
            );
            if ($admin !== null) {
                return 'administrative_role_assignment';
            }
        }

        if ($this->hasTable('dialogs') && $this->hasTable('user_to_dialogs')) {
            $ownedGroup = $this->db->fetchValue(
                "SELECT 1 FROM user_to_dialogs owner_link "
                . 'JOIN dialogs d ON d.id=owner_link.dialog_id '
                . "WHERE owner_link.user_id=:user_id AND owner_link.role='owner' AND owner_link.is_deleted=0 "
                . "AND d.type='group' LIMIT 1",
                [':user_id' => $userId]
            );
            if ($ownedGroup !== null) {
                return 'owned_shared_messenger_group';
            }
        }

        if ($this->hasTable('task_boards')) {
            $sql = "SELECT 1 FROM task_boards b WHERE b.owner_user_id=:user_id AND (b.audience='all_active'";
            $params = [':user_id' => $userId];
            if ($this->hasTable('task_board_members')) {
                $sql .= ' OR EXISTS (SELECT 1 FROM task_board_members m WHERE m.board_id=b.id AND m.user_id<>:other_user_id)';
                $params[':other_user_id'] = $userId;
            }
            $sql .= ') LIMIT 1';
            $ownedBoard = $this->db->fetchValue($sql, $params);
            if ($ownedBoard !== null) {
                return 'owned_shared_task_board';
            }
        }

        return null;
    }

    /** @return list<string> */
    private function identityPaths(int $userId): array
    {
        return [
            $this->privateRoot . DIRECTORY_SEPARATOR . 'users'
                . DIRECTORY_SEPARATOR . $userId . DIRECTORY_SEPARATOR . 'avatar' . DIRECTORY_SEPARATOR . 'avatar.jpg',
        ];
    }

    /** @param list<string> $paths @param array<string,mixed> $result */
    private function removePaths(array $paths, array &$result): bool
    {
        $ok = true;
        foreach (array_values(array_unique($paths)) as $storedPath) {
            $status = $this->removeManagedFile($storedPath);
            if ($status === 'deleted') {
                $result['files_deleted']++;
            } elseif ($status === 'missing') {
                $result['files_missing']++;
            } elseif ($status === 'blocked') {
                $result['files_blocked']++;
                $ok = false;
            } else {
                $result['files_failed']++;
                $ok = false;
            }
        }
        return $ok;
    }

    private function removeManagedFile(string $storedPath): string
    {
        $storedPath = trim($storedPath);
        if ($storedPath === '') {
            return 'missing';
        }

        $candidate = $this->isAbsolutePath($storedPath)
            ? $storedPath
            : $this->appRoot . DIRECTORY_SEPARATOR . ltrim($storedPath, '/\\');

        $allowedRoots = [
            $this->privateRoot,
            $this->appRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'file_manager',
            $this->appRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'notes',
            $this->appRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'messenger',
            $this->appRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'users',
        ];

        $lexicalAllowed = false;
        foreach ($allowedRoots as $root) {
            if ($this->isPathWithin($candidate, $root)) {
                $lexicalAllowed = true;
                break;
            }
        }
        if (!$lexicalAllowed) {
            return 'blocked';
        }

        if (!file_exists($candidate)) {
            return 'missing';
        }
        if (is_link($candidate) || !is_file($candidate)) {
            return 'blocked';
        }

        $real = realpath($candidate);
        if (!is_string($real)) {
            return 'failed';
        }
        $realAllowed = false;
        foreach ($allowedRoots as $root) {
            $realRoot = realpath($root);
            if (is_string($realRoot) && $this->isPathWithin($real, $realRoot)) {
                $realAllowed = true;
                break;
            }
        }
        if (!$realAllowed) {
            return 'blocked';
        }

        return @unlink($real) ? 'deleted' : 'failed';
    }

    private function removeEmptyUserStorage(int $userId): void
    {
        $paths = [
            $this->privateRoot . DIRECTORY_SEPARATOR . 'users' . DIRECTORY_SEPARATOR . $userId . DIRECTORY_SEPARATOR . 'avatar',
            $this->privateRoot . DIRECTORY_SEPARATOR . 'users' . DIRECTORY_SEPARATOR . $userId,
        ];
        foreach ($paths as $path) {
            if (is_dir($path) && !is_link($path)) {
                @rmdir($path);
            }
        }
    }

    /** @param array<string,mixed> $result */
    private function addPurged(array &$result, string $key, int $count): void
    {
        $result['purged'][$key] = (int) ($result['purged'][$key] ?? 0) + $count;
    }

    /** @return array<string,array{table:string,where:string}> */
    private function softDeleteDefinitions(): array
    {
        return [
            'note_attachments' => [
                'table' => 'note_attachments',
                'where' => 'is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff',
            ],
            'notes' => [
                'table' => 'notes',
                'where' => 'is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff',
            ],
            'user_files' => [
                'table' => 'user_files',
                'where' => 'is_deleted = 1 AND updated_at < :cutoff',
            ],
            'messenger_attachments' => [
                'table' => 'messenger_attachments',
                'where' => 'is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff',
            ],
            'messages' => [
                'table' => 'messages',
                'where' => 'is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff',
            ],
            'tasks' => [
                'table' => 'tasks',
                'where' => 'is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff',
            ],
            'task_board_items' => [
                'table' => 'task_board_items',
                'where' => 'is_deleted = 1 AND deleted_at IS NOT NULL AND deleted_at < :cutoff',
            ],
            'task_categories' => [
                'table' => 'task_categories',
                'where' => 'is_deleted = 1 AND updated_at < :cutoff',
            ],
        ];
    }

    private function hasTable(string $table): bool
    {
        if (isset($this->tableCache[$table])) {
            return $this->tableCache[$table];
        }
        $exists = $this->db->fetchValue(
            'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table LIMIT 1',
            [':table' => $table]
        ) !== null;
        return $this->tableCache[$table] = $exists;
    }

    /** @return array{0:int,1:int,2:int} */
    private function normalize(int $softDeleteDays, int $accountDays, int $limit): array
    {
        $softDeleteDays = max(1, min(3650, $softDeleteDays));
        $accountDays = max(1, min(3650, $accountDays));
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        return [$softDeleteDays, $accountDays, $limit];
    }

    private function cutoff(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - ($days * 86400));
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }

    private function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $parts = [];
        foreach (explode('/', $normalized) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        $prefix = str_starts_with($normalized, '/') ? '/' : '';
        return $prefix . implode('/', $parts);
    }

    private function isPathWithin(string $candidate, string $root): bool
    {
        $candidate = rtrim($this->normalizePath($candidate), '/');
        $root = rtrim($this->normalizePath($root), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $candidate = strtolower($candidate);
            $root = strtolower($root);
        }
        return $candidate === $root || str_starts_with($candidate . '/', $root . '/');
    }
}
