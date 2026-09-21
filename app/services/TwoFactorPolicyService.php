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
        $value = $this->db->fetchValue(
            'SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1',
            [':setting_key' => self::SETTING_KEY]
        );

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'required'], true);
    }

    public function setRequired(bool $required): void
    {
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
                ':description' => 'Require TOTP two-factor authentication for every active user',
            ]
        );
    }
}
