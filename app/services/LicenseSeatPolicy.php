<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use RuntimeException;
use Throwable;

/**
 * Enforces the optional signed per-installation user-seat limit.
 *
 * The workspace_license_token row is used as the serialization point for
 * license activation, user creation and account reactivation. This keeps the
 * COUNT(active users) + write decision safe under concurrent requests.
 */
final class LicenseSeatPolicy
{
    private DatabaseManager $db;
    private LicenseService $licenses;

    public function __construct(?DatabaseManager $db = null, ?LicenseService $licenses = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance();
        $this->licenses = $licenses ?? new LicenseService($this->db);
    }

    /**
     * Run an operation that consumes one active-user seat.
     *
     * If the caller already owns a DB transaction (for example managed invite
     * registration), the lock remains held until that outer transaction ends.
     */
    public function withAvailableSeat(callable $operation): mixed
    {
        return $this->withinTransaction(function () use ($operation): mixed {
            $this->lockLicenseRow();

            $status = $this->licenses->status();
            $maxUsers = $this->enforcedMaxUsers($status);
            if ($maxUsers !== null) {
                $activeUsers = $this->activeUsers();
                if ($activeUsers >= $maxUsers) {
                    throw new DomainException(
                        "Достигнут лимит лицензии: {$maxUsers} пользователей. "
                        . 'Для добавления нового пользователя обновите лицензию.',
                        409
                    );
                }
            }

            return $operation();
        });
    }

    /**
     * Persist a newly verified license only when its signed max_users value can
     * accommodate the installation's current active-user population.
     *
     * @param array<string,mixed> $payload verified signed payload
     */
    public function withLicenseActivation(array $payload, callable $persist): mixed
    {
        return $this->withinTransaction(function () use ($payload, $persist): mixed {
            $this->lockLicenseRow();

            $maxUsers = $this->payloadMaxUsers($payload);
            if ($maxUsers !== null) {
                $activeUsers = $this->activeUsers();
                if ($activeUsers > $maxUsers) {
                    throw new DomainException(
                        "Нельзя активировать лицензию на {$maxUsers} пользователей: "
                        . "сейчас активно {$activeUsers}. Сначала уменьшите число активных аккаунтов "
                        . 'или используйте лицензию с большим лимитом.',
                        409
                    );
                }
            }

            return $persist();
        });
    }

    /**
     * @param array<string,mixed> $licenseStatus
     * @return array{active_users:int,max_users:?int,remaining_users:?int,limited:bool}
     */
    public function usage(array $licenseStatus): array
    {
        $activeUsers = $this->activeUsers();
        $maxUsers = $this->displayMaxUsers($licenseStatus);

        return [
            'active_users' => $activeUsers,
            'max_users' => $maxUsers,
            'remaining_users' => $maxUsers === null ? null : max(0, $maxUsers - $activeUsers),
            'limited' => (bool) ($licenseStatus['valid'] ?? false) && $maxUsers !== null,
        ];
    }

    public function activeUsers(): int
    {
        return max(0, (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM users WHERE is_active = 1'
        ));
    }

    /** @param array<string,mixed> $status */
    private function enforcedMaxUsers(array $status): ?int
    {
        if (!(bool) ($status['valid'] ?? false)) {
            return null;
        }

        return $this->displayMaxUsers($status);
    }

    /** @param array<string,mixed> $status */
    private function displayMaxUsers(array $status): ?int
    {
        if (!array_key_exists('max_users', $status) || $status['max_users'] === null) {
            return null;
        }

        $value = $status['max_users'];
        if (!is_int($value) || $value < 1 || $value > LicenseVerifier::MAX_USERS_HARD_LIMIT) {
            throw new RuntimeException('License status contains an invalid max_users value');
        }

        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function payloadMaxUsers(array $payload): ?int
    {
        if (!array_key_exists('max_users', $payload) || $payload['max_users'] === null) {
            return null;
        }

        $value = $payload['max_users'];
        if (!is_int($value) || $value < 1 || $value > LicenseVerifier::MAX_USERS_HARD_LIMIT) {
            throw new RuntimeException('Verified license payload contains an invalid max_users value');
        }

        return $value;
    }

    private function lockLicenseRow(): void
    {
        // Canonical installs seed this row. INSERT IGNORE also makes the lock
        // target explicit for upgraded/partially repaired installations.
        $this->db->execute(
            "INSERT IGNORE INTO system_settings "
            . "(setting_key,setting_value,setting_type,category,description,is_editable) "
            . "VALUES (:key,'','string','licensing',"
            . "'Signed installation-wide Workspace Organizer license token',0)",
            [':key' => LicenseService::LICENSE_TOKEN_KEY]
        );

        $row = $this->db->fetchOne(
            'SELECT setting_key FROM system_settings '
            . 'WHERE setting_key = :key LIMIT 1 FOR UPDATE',
            [':key' => LicenseService::LICENSE_TOKEN_KEY]
        );
        if ($row === null) {
            throw new RuntimeException('Unable to lock license state for user-seat enforcement');
        }
    }

    private function withinTransaction(callable $operation): mixed
    {
        $ownsTransaction = !$this->db->getPdo()->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->db->endTransaction(true);
            }
            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                $this->db->endTransaction(false);
            }
            throw $e;
        }
    }
}
