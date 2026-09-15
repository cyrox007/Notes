<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class RegistrationPolicyService
{
    public const MODE_DISABLED = 'disabled';
    public const MODE_OPEN = 'open';
    public const MODE_INVITE = 'invite';

    private const MODE_SETTING = 'registration_mode';
    private const INVITES_SETTING = 'registration_invites_json';
    private const MAX_INVITES = 100;

    private PermissionService $permissions;

    public function __construct(private ?DatabaseManager $db = null, ?PermissionService $permissions = null)
    {
        $this->db ??= DatabaseManager::getInstance();
        $this->permissions = $permissions ?? new PermissionService($this->db);
    }

    public function mode(): string
    {
        $explicit = $this->explicitMode();
        if ($explicit !== null) {
            return $explicit;
        }

        return $this->legacyInviteCode() !== '' ? self::MODE_INVITE : self::MODE_DISABLED;
    }

    public function isRegistrationVisible(): bool
    {
        return $this->mode() !== self::MODE_DISABLED;
    }

    public function requiresInvite(): bool
    {
        return $this->mode() === self::MODE_INVITE;
    }

    public function saveMode(int $actorId, string $mode): void
    {
        $this->permissions->requirePermission($actorId, 'admin.settings.manage');
        $mode = strtolower(trim($mode));
        if (!in_array($mode, [self::MODE_DISABLED, self::MODE_OPEN, self::MODE_INVITE], true)) {
            throw new InvalidArgumentException('Неизвестный режим регистрации', 422);
        }

        $this->upsertSetting(
            self::MODE_SETTING,
            $mode,
            'string',
            'registration',
            'Public registration policy: disabled, open or invite-only'
        );
    }

    /**
     * @return list<array{id:string,label:string,max_uses:int,used_count:int,expires_at:?string,created_at:string,revoked_at:?string,status:string}>
     */
    public function listInvites(int $actorId): array
    {
        $this->permissions->requirePermission($actorId, 'admin.settings.manage');
        return $this->decorateInvites($this->readInvites());
    }

    /** @return array{code:string,id:string,label:string,max_uses:int,expires_at:?string} */
    public function createInvite(
        int $actorId,
        string $label,
        int $maxUses = 1,
        ?string $expiresAt = null
    ): array {
        $this->permissions->requirePermission($actorId, 'admin.settings.manage');

        $label = trim($label);
        if ($label === '') {
            $label = 'Приглашение ' . date('Y-m-d H:i');
        }
        if (mb_strlen($label) > 100) {
            throw new InvalidArgumentException('Название инвайта не должно превышать 100 символов', 422);
        }
        if ($maxUses < 1 || $maxUses > 1000) {
            throw new InvalidArgumentException('Количество использований инвайта должно быть от 1 до 1000', 422);
        }

        $normalizedExpiry = $this->normalizeExpiry($expiresAt);
        $code = $this->generateInviteCode();
        $record = [
            'id' => bin2hex(random_bytes(8)),
            'hash' => hash('sha256', $code),
            'label' => $label,
            'max_uses' => $maxUses,
            'used_count' => 0,
            'expires_at' => $normalizedExpiry,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $actorId,
            'revoked_at' => null,
        ];

        $this->db->beginTransaction();
        try {
            $invites = $this->readInvitesForUpdate();
            if (count($invites) >= self::MAX_INVITES) {
                throw new DomainException('Достигнут лимит сохранённых инвайтов. Отзовите старые приглашения.', 409);
            }
            $invites[] = $record;
            $this->writeInvites($invites);
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }

        return [
            'code' => $code,
            'id' => $record['id'],
            'label' => $label,
            'max_uses' => $maxUses,
            'expires_at' => $normalizedExpiry,
        ];
    }

    public function revokeInvite(int $actorId, string $inviteId): void
    {
        $this->permissions->requirePermission($actorId, 'admin.settings.manage');
        if (preg_match('/^[a-f0-9]{16}$/', $inviteId) !== 1) {
            throw new InvalidArgumentException('Некорректный идентификатор инвайта', 422);
        }

        $this->db->beginTransaction();
        try {
            $invites = $this->readInvitesForUpdate();
            $found = false;
            foreach ($invites as &$invite) {
                if (($invite['id'] ?? '') !== $inviteId) {
                    continue;
                }
                $invite['revoked_at'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
            unset($invite);

            if (!$found) {
                throw new DomainException('Инвайт не найден', 404);
            }
            $this->writeInvites($invites);
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    /** @param array<string,mixed> $input */
    public function register(array $input, ?string $inviteCode, UserProvisioningService $users): int
    {
        $inviteCode = trim((string) $inviteCode);
        $this->db->beginTransaction();
        try {
            $explicitMode = $this->explicitModeForUpdate();
            $mode = $explicitMode ?? ($this->legacyInviteCode() !== '' ? self::MODE_INVITE : self::MODE_DISABLED);
            if ($mode === self::MODE_DISABLED) {
                throw new DomainException('Регистрация отключена администратором', 403);
            }

            $managedInviteIndex = null;
            $invites = [];
            if ($mode === self::MODE_INVITE) {
                if ($inviteCode === '') {
                    throw new DomainException('Введите действующий код приглашения', 403);
                }

                $legacyAccepted = $explicitMode === null
                    && $this->legacyInviteCode() !== ''
                    && hash_equals($this->legacyInviteCode(), $inviteCode);

                if (!$legacyAccepted) {
                    $invites = $this->readInvitesForUpdate();
                    $managedInviteIndex = $this->findUsableInviteIndex($invites, $inviteCode);
                    if ($managedInviteIndex === null) {
                        throw new DomainException('Код приглашения недействителен, исчерпан или просрочен', 403);
                    }
                }
            }

            $userId = $users->createSelfService($input);

            if ($managedInviteIndex !== null) {
                $invites[$managedInviteIndex]['used_count'] = (int) ($invites[$managedInviteIndex]['used_count'] ?? 0) + 1;
                $this->writeInvites($invites);
            }

            $this->db->endTransaction(true);
            return $userId;
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    private function explicitMode(): ?string
    {
        $value = $this->db->fetchValue(
            'SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1',
            [':setting_key' => self::MODE_SETTING]
        );
        return $this->normalizeModeValue($value);
    }

    private function explicitModeForUpdate(): ?string
    {
        $row = $this->db->fetchOne(
            'SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1 FOR UPDATE',
            [':setting_key' => self::MODE_SETTING]
        );
        return $this->normalizeModeValue($row['setting_value'] ?? null);
    }

    private function normalizeModeValue(mixed $value): ?string
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';
        return in_array($value, [self::MODE_DISABLED, self::MODE_OPEN, self::MODE_INVITE], true)
            ? $value
            : null;
    }

    /** @return list<array<string,mixed>> */
    private function readInvites(): array
    {
        $raw = $this->db->fetchValue(
            'SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1',
            [':setting_key' => self::INVITES_SETTING]
        );
        return $this->decodeInvites($raw);
    }

    /** @return list<array<string,mixed>> */
    private function readInvitesForUpdate(): array
    {
        $row = $this->db->fetchOne(
            'SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1 FOR UPDATE',
            [':setting_key' => self::INVITES_SETTING]
        );
        return $this->decodeInvites($row['setting_value'] ?? null);
    }

    /** @return list<array<string,mixed>> */
    private function decodeInvites(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $invite) {
            if (!is_array($invite) || !isset($invite['id'], $invite['hash'])) {
                continue;
            }
            $result[] = $invite;
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $invites */
    private function writeInvites(array $invites): void
    {
        $this->upsertSetting(
            self::INVITES_SETTING,
            json_encode(array_values($invites), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'json',
            'registration',
            'Hashed managed registration invitations'
        );
    }

    private function upsertSetting(string $key, string $value, string $type, string $category, string $description): void
    {
        $this->db->execute(
            'INSERT INTO system_settings (setting_key,setting_value,setting_type,category,description,is_editable) '
            . 'VALUES (:setting_key,:setting_value,:setting_type,:category,:description,1) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type), '
            . 'category = VALUES(category), description = VALUES(description), updated_at = CURRENT_TIMESTAMP',
            [
                ':setting_key' => $key,
                ':setting_value' => $value,
                ':setting_type' => $type,
                ':category' => $category,
                ':description' => $description,
            ]
        );
    }

    /** @param list<array<string,mixed>> $invites */
    private function findUsableInviteIndex(array $invites, string $code): ?int
    {
        $hash = hash('sha256', $code);
        $now = time();
        foreach ($invites as $index => $invite) {
            if (!isset($invite['hash']) || !hash_equals((string) $invite['hash'], $hash)) {
                continue;
            }
            if (!empty($invite['revoked_at'])) {
                return null;
            }
            $maxUses = max(1, (int) ($invite['max_uses'] ?? 1));
            if ((int) ($invite['used_count'] ?? 0) >= $maxUses) {
                return null;
            }
            $expiresAt = (string) ($invite['expires_at'] ?? '');
            if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < $now) {
                return null;
            }
            return $index;
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $invites
     * @return list<array{id:string,label:string,max_uses:int,used_count:int,expires_at:?string,created_at:string,revoked_at:?string,status:string}>
     */
    private function decorateInvites(array $invites): array
    {
        $result = [];
        $now = time();
        foreach ($invites as $invite) {
            $maxUses = max(1, (int) ($invite['max_uses'] ?? 1));
            $usedCount = max(0, (int) ($invite['used_count'] ?? 0));
            $expiresAt = isset($invite['expires_at']) && is_string($invite['expires_at']) && $invite['expires_at'] !== ''
                ? $invite['expires_at']
                : null;
            $revokedAt = isset($invite['revoked_at']) && is_string($invite['revoked_at']) && $invite['revoked_at'] !== ''
                ? $invite['revoked_at']
                : null;

            $status = 'active';
            if ($revokedAt !== null) {
                $status = 'revoked';
            } elseif ($usedCount >= $maxUses) {
                $status = 'exhausted';
            } elseif ($expiresAt !== null && strtotime($expiresAt) !== false && strtotime($expiresAt) < $now) {
                $status = 'expired';
            }

            $result[] = [
                'id' => (string) ($invite['id'] ?? ''),
                'label' => (string) ($invite['label'] ?? 'Приглашение'),
                'max_uses' => $maxUses,
                'used_count' => $usedCount,
                'expires_at' => $expiresAt,
                'created_at' => (string) ($invite['created_at'] ?? ''),
                'revoked_at' => $revokedAt,
                'status' => $status,
            ];
        }
        return array_reverse($result);
    }

    private function normalizeExpiry(?string $expiresAt): ?string
    {
        $expiresAt = trim((string) $expiresAt);
        if ($expiresAt === '') {
            return null;
        }
        try {
            $date = new DateTimeImmutable($expiresAt);
        } catch (\Exception $e) {
            throw new InvalidArgumentException('Некорректный срок действия инвайта', 422, $e);
        }
        if ($date->getTimestamp() <= time()) {
            throw new InvalidArgumentException('Срок действия инвайта должен быть в будущем', 422);
        }
        return $date->format('Y-m-d H:i:s');
    }

    private function generateInviteCode(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    private function legacyInviteCode(): string
    {
        return trim((string) (getenv('REGISTRATION_INVITE_CODE') ?: ''));
    }
}
