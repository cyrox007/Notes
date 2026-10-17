-- Канонические настройки и служебные таблицы ядра.
-- Настройки конкретных модулей принадлежат свежей схеме соответствующего модуля.

CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NOT NULL,
    `setting_type` ENUM('string','integer','boolean','json') NOT NULL DEFAULT 'string',
    `category` VARCHAR(50) NOT NULL DEFAULT 'general',
    `description` VARCHAR(255) DEFAULT NULL,
    `is_editable` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_system_settings_key` (`setting_key`),
    INDEX `idx_system_settings_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Общий журнал изменяющих команд. Он не хранит содержимое сущности, только
-- идентичность команды, fingerprint запроса и минимальный результат повтора.
CREATE TABLE IF NOT EXISTS `mutation_commands` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `actor_key` VARCHAR(96) NOT NULL,
    `command_id` VARCHAR(96) NOT NULL,
    `scope` VARCHAR(64) NOT NULL,
    `action` VARCHAR(96) NOT NULL,
    `entity_type` VARCHAR(64) NOT NULL,
    `entity_uid` VARCHAR(128) NOT NULL,
    `request_hash` CHAR(64) NOT NULL,
    `operation_token` CHAR(32) NOT NULL,
    `status` ENUM('processing','applied','conflict') NOT NULL DEFAULT 'processing',
    `response_json` LONGTEXT DEFAULT NULL,
    `result_version` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `completed_at` DATETIME(6) DEFAULT NULL,
    UNIQUE KEY `uq_mutation_commands_actor_command` (`actor_key`,`command_id`),
    KEY `idx_mutation_commands_entity` (`scope`,`entity_type`,`entity_uid`,`created_at`),
    KEY `idx_mutation_commands_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings`
    (`setting_key`,`setting_value`,`setting_type`,`category`,`description`,`is_editable`)
VALUES
    ('installation_id',LOWER(UUID()),'string','licensing','Stable installation identifier used to bind signed licenses',0),
    ('workspace_license_token','','string','licensing','Signed installation-wide Workspace Organizer license token',0),
    ('two_factor_required','0','boolean','security','Требовать TOTP-аутентификацию для каждого активного пользователя',1)
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);
