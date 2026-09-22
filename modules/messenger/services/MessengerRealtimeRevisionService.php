<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;

/**
 * Cross-process bridge between HTTP fallback mutations and the native
 * WebSocket process. The revision lives in the shared database so the
 * supported remote WS-node topology does not depend on a shared filesystem.
 */
final class MessengerRealtimeRevisionService
{
    public const SETTING_KEY = 'messenger_realtime_revision';

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    public function current(): int
    {
        $row = $this->db->fetchOne(
            'SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1',
            [':setting_key' => self::SETTING_KEY]
        );

        $value = trim((string) ($row['setting_value'] ?? '0'));
        return ctype_digit($value) ? (int) $value : 0;
    }

    public function bump(): int
    {
        $this->db->execute(
            "INSERT INTO system_settings
                (setting_key, setting_value, setting_type, category, description, is_editable)
             VALUES
                (:setting_key, '1', 'integer', 'messenger', :description, 0)
             ON DUPLICATE KEY UPDATE
                setting_value = CAST(
                    CAST(COALESCE(NULLIF(setting_value, ''), '0') AS UNSIGNED) + 1
                    AS CHAR
                ),
                setting_type = 'integer',
                category = 'messenger',
                is_editable = 0,
                updated_at = CURRENT_TIMESTAMP",
            [
                ':setting_key' => self::SETTING_KEY,
                ':description' => 'Shared Messenger realtime revision used to bridge HTTP fallback and WebSocket delivery',
            ]
        );

        return $this->current();
    }
}
