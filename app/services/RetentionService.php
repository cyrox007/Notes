<?php

declare(strict_types=1);

namespace App\Services;

require_once dirname(__DIR__, 2) . '/core/SecurityEventLog.php';

use Core\BackgroundAccountRetention;
use Core\BackgroundRetentionCleanup;
use Core\RetentionCleanupContext;
use Core\RetentionFileCleanup;
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
    private ?BackgroundAccountRetention $accountRetention = null;
    private string $privateRoot;
    private RetentionFileCleanup $retentionFiles;
    private ?RetentionCleanupContext $cleanupContext = null;
    private ?BackgroundRetentionCleanup $moduleCleanup = null;

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
        $this->retentionFiles = new RetentionFileCleanup($this->appRoot, $this->privateRoot);
    }

    /** @return array<string,mixed> */
    public function preview(int $softDeleteDays, int $accountDays, int $limit = 100): array
    {
        [$softDeleteDays, $accountDays, $limit] = $this->normalize($softDeleteDays, $accountDays, $limit);
        $softCutoff = $this->cutoff($softDeleteDays);
        $accountCutoff = $this->cutoff($accountDays);
        $accountDueAt = gmdate('Y-m-d H:i:s');

        $soft = $this->moduleCleanup()->preview($softCutoff, $limit, $this->cleanupContext());

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

        $this->moduleCleanup()->apply($softCutoff, $limit, $this->cleanupContext(), $result);
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

                // Повторить проверку после захвата строки учётной записи.
                // Не удалять аватар, если владелец стал заблокированным
                // между предварительной проверкой и транзакцией.
                $confirmedReason = $this->accountBlockReason($userId);
                if ($confirmedReason !== null) {
                    $this->db->endTransaction(false);
                    $result['accounts_blocked']++;
                    SecurityEventLog::emit(
                        'retention.account_blocked', 'warning', 'retention', 'cli', null,
                        ['user_id' => $userId, 'reason' => $confirmedReason]
                    );
                    continue;
                }

                // Файлы удаляются лишь после обеих проверок владения.
                if (!$this->removePaths($this->identityPaths($userId), $result)) {
                    $this->db->endTransaction(false);
                    $result['accounts_failed']++;
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
                $this->moduleCleanup()->purgeAccountData($userId, $this->cleanupContext());

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

        // Владелец данных сам проверяет препятствия для обезличивания.
        // Фоновый сервис загружается по manifest даже при disabled/unlicensed.
        return $this->backgroundAccountRetention()->blockingReason($userId);
    }

    private function backgroundAccountRetention(): BackgroundAccountRetention
    {
        return $this->accountRetention ??= new BackgroundAccountRetention(
            $this->db,
            $this->appRoot . '/modules'
        );
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
        return $this->retentionFiles->removePaths($paths, $result);
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

    private function cleanupContext(): RetentionCleanupContext
    {
        return $this->cleanupContext ??= new RetentionCleanupContext($this->db, $this->retentionFiles);
    }

    private function moduleCleanup(): BackgroundRetentionCleanup
    {
        return $this->moduleCleanup ??= new BackgroundRetentionCleanup(
            $this->db,
            $this->appRoot . '/modules'
        );
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
