<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use Core\LocalControlPlaneContext;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Installation-wide license state boundary.
 *
 * License failures never delete, rewrite or migrate user data. This service only
 * reads/writes the two licensing settings: installation_id and signed token.
 */
final class LicenseService
{
    public const INSTALLATION_ID_KEY = 'installation_id';
    public const LICENSE_TOKEN_KEY = 'workspace_license_token';

    private DatabaseManager $db;
    private PermissionService $permissions;
    private LicenseVerifier $verifier;

    public function __construct(
        ?DatabaseManager $db = null,
        ?PermissionService $permissions = null,
        ?LicenseVerifier $verifier = null
    ) {
        $this->db = $db ?? DatabaseManager::getInstance();
        $this->permissions = $permissions ?? new PermissionService($this->db);
        $this->verifier = $verifier ?? new LicenseVerifier();
    }

    public function installationId(): string
    {
        $value = trim((string) ($this->db->fetchValue(
            'SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1',
            [':key' => self::INSTALLATION_ID_KEY]
        ) ?? ''));

        if ($value === '') {
            $candidate = $this->uuidV4();
            $this->db->execute(
                "INSERT INTO system_settings (setting_key,setting_value,setting_type,category,description,is_editable)
                 VALUES (:key,:value,'string','licensing','Stable installation identifier used to bind signed licenses',0)
                 ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key)",
                [':key' => self::INSTALLATION_ID_KEY, ':value' => $candidate]
            );
            $value = trim((string) ($this->db->fetchValue(
                'SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1',
                [':key' => self::INSTALLATION_ID_KEY]
            ) ?? ''));
        }

        $value = strtolower($value);
        if (!$this->isUuid($value)) {
            // Never silently replace an existing identifier: that could detach a
            // valid installation-bound license from the installation.
            throw new RuntimeException('Некорректный installation_id в system_settings');
        }

        return $value;
    }

    /**
     * Safe read for health/status surfaces. It performs no authorization check
     * and never exposes the full stored token.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $installationId = $this->installationId();
        $token = $this->storedToken();

        if ($token === '') {
            return $this->decorateStatus([
                'valid' => false,
                'code' => 'unlicensed',
                'message' => 'Лицензионный ключ не установлен',
                'key_id' => null,
                'payload' => null,
            ], $installationId, false);
        }

        if (!$this->verifier->hasTrustedKeys()) {
            return $this->decorateStatus([
                'valid' => false,
                'code' => 'trust_not_configured',
                'message' => 'В этой сборке ещё не настроен публичный ключ проверки лицензий',
                'key_id' => null,
                'payload' => null,
            ], $installationId, true);
        }

        return $this->decorateStatus(
            $this->verifier->verify($token, $installationId),
            $installationId,
            true
        );
    }

    /** @return array<string,mixed> */
    public function snapshot(int $actorId): array
    {
        $this->permissions->requirePermission($actorId, 'admin.settings.manage');
        $status = $this->status();
        $status['can_manage'] = $this->permissions->hasRole($actorId, 'superadmin');
        $status['trusted_key_ids'] = $this->verifier->trustedKeyIds();
        $status['trust_configured'] = $this->verifier->hasTrustedKeys();
        return $status;
    }

    /** @return array<string,mixed> */
    public function activate(int $actorId, string $token): array
    {
        $this->requireLicenseManager($actorId);
        return $this->activateToken($token);
    }

    /**
     * Local recovery path for the installation control plane.
     *
     * The context can only be created in PHP CLI, so HTTP code cannot use this
     * method to bypass the normal superadmin/RBAC authorization boundary.
     *
     * @return array<string,mixed>
     */
    public function activateFromControlPlane(LocalControlPlaneContext $context, string $token): array
    {
        $context->assertCli();
        return $this->activateToken($token);
    }

    /** @return array<string,mixed> */
    public function clear(int $actorId): array
    {
        $this->requireLicenseManager($actorId);
        return $this->clearToken();
    }

    /** @return array<string,mixed> */
    public function clearFromControlPlane(LocalControlPlaneContext $context): array
    {
        $context->assertCli();
        return $this->clearToken();
    }

    /** @return array<string,mixed> */
    private function activateToken(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new InvalidArgumentException('Вставьте лицензионный ключ', 422);
        }
        if (!$this->verifier->hasTrustedKeys()) {
            throw new DomainException(
                'Активация недоступна: в этой сборке не настроен публичный ключ проверки лицензий',
                503
            );
        }

        $installationId = $this->installationId();
        $verification = $this->verifier->verify($token, $installationId);
        if (!$verification['valid']) {
            throw new DomainException((string) $verification['message'], 422);
        }

        $this->db->execute(
            "INSERT INTO system_settings (setting_key,setting_value,setting_type,category,description,is_editable)
             VALUES (:key,:value,'string','licensing','Signed installation-wide Workspace Organizer license token',0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP",
            [':key' => self::LICENSE_TOKEN_KEY, ':value' => $token]
        );

        return $this->status();
    }

    /** @return array<string,mixed> */
    private function clearToken(): array
    {
        $this->db->execute(
            "INSERT INTO system_settings (setting_key,setting_value,setting_type,category,description,is_editable)
             VALUES (:key,'','string','licensing','Signed installation-wide Workspace Organizer license token',0)
             ON DUPLICATE KEY UPDATE setting_value = '', updated_at = CURRENT_TIMESTAMP",
            [':key' => self::LICENSE_TOKEN_KEY]
        );
        return $this->status();
    }

    public function storedTokenExists(): bool
    {
        return $this->storedToken() !== '';
    }

    private function storedToken(): string
    {
        return trim((string) ($this->db->fetchValue(
            'SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1',
            [':key' => self::LICENSE_TOKEN_KEY]
        ) ?? ''));
    }

    /** @param array<string,mixed> $verification @return array<string,mixed> */
    private function decorateStatus(array $verification, string $installationId, bool $hasToken): array
    {
        $payload = is_array($verification['payload'] ?? null) ? $verification['payload'] : null;
        return [
            'installation_id' => $installationId,
            'has_token' => $hasToken,
            'valid' => (bool) ($verification['valid'] ?? false),
            'code' => (string) ($verification['code'] ?? 'unknown'),
            'message' => (string) ($verification['message'] ?? 'Не удалось определить состояние лицензии'),
            'key_id' => isset($verification['key_id']) ? (string) $verification['key_id'] : null,
            'license_id' => $payload !== null ? (string) ($payload['license_id'] ?? '') : '',
            'edition' => $payload !== null ? (string) ($payload['edition'] ?? '') : '',
            'customer' => $payload !== null && isset($payload['customer']) ? (string) $payload['customer'] : '',
            'issued_at' => $payload !== null && isset($payload['issued_at']) ? (int) $payload['issued_at'] : null,
            'expires_at' => $payload !== null && array_key_exists('expires_at', $payload) && $payload['expires_at'] !== null
                ? (int) $payload['expires_at']
                : null,
            'features' => $payload !== null && isset($payload['features']) && is_array($payload['features'])
                ? array_values(array_map('strval', $payload['features']))
                : [],
        ];
    }

    private function requireLicenseManager(int $actorId): void
    {
        $this->permissions->requirePermission($actorId, 'admin.settings.manage');
        if (!$this->permissions->hasRole($actorId, 'superadmin')) {
            throw new DomainException('Управление лицензией доступно только суперадминистратору', 403);
        }
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $value
        ) === 1;
    }
}
