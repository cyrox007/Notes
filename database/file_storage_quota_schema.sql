-- Canonical File Manager quota schema.
-- Requires core users and system_settings tables.

CREATE TABLE IF NOT EXISTS `user_storage_quotas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `quota_bytes` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_storage_quota_user` (`user_id`),
    CONSTRAINT `fk_user_storage_quota_user`
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings`
    (`setting_key`,`setting_value`,`setting_type`,`category`,`description`,`is_editable`)
VALUES
    ('file_manager_default_quota_bytes','1073741824','integer','file_manager','Default File Manager storage quota per user in bytes',1)
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);
