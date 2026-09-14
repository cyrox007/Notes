-- Reconcile the additive legacy shapes that pre-date typed system settings and
-- complete per-user storage quota metadata. This migration is intentionally
-- ordered before 20260913_system_settings_storage_quota.sql in bin/migrate.php:
-- fresh installs are a no-op here, while partial legacy tables are upgraded so
-- the canonical migration can safely seed/verify the final contract.

DELIMITER //
CREATE PROCEDURE `workspace_reconcile_storage_quota_legacy`()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'system_settings'
    ) THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings' AND column_name = 'setting_type'
        ) THEN
            ALTER TABLE `system_settings`
                ADD COLUMN `setting_type` ENUM('string','integer','boolean','json') NOT NULL DEFAULT 'string' AFTER `setting_value`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings' AND column_name = 'category'
        ) THEN
            ALTER TABLE `system_settings`
                ADD COLUMN `category` VARCHAR(50) NOT NULL DEFAULT 'general' AFTER `setting_type`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings' AND column_name = 'description'
        ) THEN
            ALTER TABLE `system_settings`
                ADD COLUMN `description` VARCHAR(255) DEFAULT NULL AFTER `category`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings' AND column_name = 'is_editable'
        ) THEN
            ALTER TABLE `system_settings`
                ADD COLUMN `is_editable` TINYINT(1) NOT NULL DEFAULT 1 AFTER `description`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings' AND column_name = 'created_at'
        ) THEN
            ALTER TABLE `system_settings`
                ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `is_editable`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings' AND column_name = 'updated_at'
        ) THEN
            ALTER TABLE `system_settings`
                ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'system_settings'
              AND non_unique = 0
              AND column_name = 'setting_key'
              AND seq_in_index = 1
        ) THEN
            ALTER TABLE `system_settings`
                ADD UNIQUE KEY `uq_system_settings_key` (`setting_key`);
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'system_settings'
              AND column_name = 'category'
              AND seq_in_index = 1
        ) THEN
            ALTER TABLE `system_settings`
                ADD INDEX `idx_system_settings_category` (`category`);
        END IF;

        -- Preserve an existing administrator-defined quota value while upgrading
        -- the metadata needed by the typed settings service.
        UPDATE `system_settings`
        SET `setting_type` = 'integer',
            `category` = 'file_manager',
            `is_editable` = 1
        WHERE `setting_key` = 'file_manager_default_quota_bytes';
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'user_storage_quotas'
    ) THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'user_storage_quotas' AND column_name = 'created_at'
        ) THEN
            ALTER TABLE `user_storage_quotas`
                ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `quota_bytes`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'user_storage_quotas' AND column_name = 'updated_at'
        ) THEN
            ALTER TABLE `user_storage_quotas`
                ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'user_storage_quotas'
              AND non_unique = 0
              AND column_name = 'user_id'
              AND seq_in_index = 1
        ) THEN
            ALTER TABLE `user_storage_quotas`
                ADD UNIQUE KEY `uq_user_storage_quota_user` (`user_id`);
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.key_column_usage k
            JOIN information_schema.referential_constraints r
              ON r.constraint_schema = k.constraint_schema
             AND r.constraint_name = k.constraint_name
            WHERE k.table_schema = DATABASE()
              AND k.table_name = 'user_storage_quotas'
              AND k.column_name = 'user_id'
              AND k.referenced_table_name = 'users'
              AND k.referenced_column_name = 'id'
              AND r.delete_rule = 'CASCADE'
        ) THEN
            ALTER TABLE `user_storage_quotas`
                ADD CONSTRAINT `fk_user_storage_quota_user`
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;
        END IF;
    END IF;
END//

CALL `workspace_reconcile_storage_quota_legacy`()//
DROP PROCEDURE `workspace_reconcile_storage_quota_legacy`//
DELIMITER ;
