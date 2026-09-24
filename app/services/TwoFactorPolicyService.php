<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;

final class TwoFactorPolicyService
{
    public const SETTING_KEY = 'two_factor_required';

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    public function required(): bool
    {
        if (!$this->schemaReady()) {
            return false;
        }

        try {
            $value = $this->db->fetchValue(
                'SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1',
                [':setting_key' => self::SETTING_KEY]
            );
        } catch (Throwable $e) {
            error_log('Не удалось прочитать политику обязательной 2FA: ' . $e->getMessage());
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'required'], true);
    }

    public function schemaReady(): bool
    {
        try {
            $count = (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM information_schema.columns "
                . "WHERE table_schema = DATABASE() AND table_name = 'users' "
                . "AND column_name IN ('totp_enabled','totp_secret','totp_last_counter','totp_recovery_codes','totp_confirmed_at')"
            );
            if ($count !== 5) {
                return false;
            }

            return $this->db->fetchValue(
                "SELECT 1 FROM information_schema.tables "
                . "WHERE table_schema = DATABASE() AND table_name = 'system_settings' LIMIT 1"
            ) !== null;
        } catch (Throwable $e) {
            error_log('Не удалось проверить готовность схемы 2FA: ' . $e->getMessage());
            return false;
        }
    }

    public function setRequired(bool $required): void
    {
        if (!$this->schemaReady()) {
            throw new DomainException(
                'Нельзя изменить политику 2FA до завершения миграций базы данных. '
                . 'Выполните php bin/migrate.php и повторите попытку.',
                409
            );
        }

        $this->db->execute(
            'INSERT INTO system_settings '
            . '(setting_key,setting_value,setting_type,category,description,is_editable) '
            . 'VALUES (:setting_key,:setting_value,\'boolean\',\'security\',:description,1) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), '
            . 'setting_type = VALUES(setting_type), category = VALUES(category), '
            . 'description = VALUES(description), is_editable = 1, updated_at = CURRENT_TIMESTAMP',
            [
                ':setting_key' => self::SETTING_KEY,
                ':setting_value' => $required ? '1' : '0',
                ':description' => 'Требовать TOTP-аутентификацию для каждого активного пользователя',
            ]
        );
    }
}
