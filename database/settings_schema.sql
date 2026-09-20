-- Legacy full-bundle settings compatibility aggregate.
--
-- New composition-aware installs use database/core_settings_schema.sql plus
-- module-owned fresh schemas. This file remains for pre-1.0 tooling and fixtures
-- that historically expected the Files default quota to be seeded here.

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

INSERT INTO `system_settings`
    (`setting_key`,`setting_value`,`setting_type`,`category`,`description`,`is_editable`)
VALUES
    ('installation_id',LOWER(UUID()),'string','licensing','Stable installation identifier used to bind signed licenses',0),
    ('workspace_license_token','','string','licensing','Signed installation-wide Workspace Organizer license token',0),
    ('file_manager_default_quota_bytes','1073741824','integer','file_manager','Default File Manager storage quota per user in bytes',1)
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);
