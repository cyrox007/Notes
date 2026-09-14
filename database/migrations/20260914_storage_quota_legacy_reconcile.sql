-- Reconcile additive legacy shapes that pre-date typed system settings and
-- complete per-user storage quota metadata. This migration is intentionally
-- ordered before 20260913_system_settings_storage_quota.sql in bin/migrate.php:
-- fresh installs are a no-op here, while supported partial legacy tables are
-- upgraded before the canonical migration seeds/verifies the final contract.
--
-- Safety rule: validate every pre-existing core/optional column and dangerous
-- data condition before the first ALTER TABLE. Unsupported shapes fail closed
-- instead of being guessed or coerced.

DELIMITER //
CREATE PROCEDURE `workspace_reconcile_storage_quota_legacy`()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'system_settings'
    ) THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings'
              AND column_name = 'id' AND data_type = 'int' AND is_nullable = 'NO'
              AND LOWER(extra) LIKE '%auto_increment%'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy system_settings.id contract';
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings'
              AND column_name = 'setting_key' AND data_type = 'varchar'
              AND character_maximum_length = 100 AND is_nullable = 'NO'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy system_settings.setting_key contract';
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings'
              AND column_name = 'setting_value' AND data_type = 'text' AND is_nullable = 'NO'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy system_settings.setting_value contract';
        END IF;

        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings' AND column_name = 'setting_type'
        ) AND NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings'
              AND column_name = 'setting_type'
              AND column_type = "enum('string','integer','boolean','json')"
              AND is_nullable = 'NO' AND LOWER(column_default) = 'string'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy system_settings.setting_type contract';
        END IF;
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings' AND column_name = 'category'
        ) AND NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings'
              AND column_name = 'category' AND data_type = 'varchar'
              AND character_maximum_length = 50 AND is_nullable = 'NO'
              AND LOWER(column_default) = 'general'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy system_settings.category contract';
        END IF;
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings' AND column_name = 'description'
        ) AND NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings'
              AND column_name = 'description' AND data_type = 'varchar'
              AND character_maximum_length = 255 AND is_nullable = 'YES'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy system_settings.description contract';
        END IF;
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings' AND column_name = 'is_editable'
        ) AND NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings'
              AND column_name = 'is_editable' AND data_type = 'tinyint'
              AND is_nullable = 'NO' AND column_default = '1'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy system_settings.is_editable contract';
        END IF;
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings' AND column_name = 'created_at'
        ) AND NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings'
              AND column_name = 'created_at' AND data_type = 'datetime'
              AND is_nullable = 'NO' AND LOWER(column_default) = 'current_timestamp'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy system_settings.created_at contract';
        END IF;
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings' AND column_name = 'updated_at'
        ) AND NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'system_settings'
              AND column_name = 'updated_at' AND data_type = 'datetime'
              AND is_nullable = 'NO' AND LOWER(column_default) = 'current_timestamp'
              AND LOWER(extra) LIKE '%on update current_timestamp%'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy system_settings.updated_at contract';
        END IF;

        IF EXISTS (
            SELECT setting_key FROM system_settings GROUP BY setting_key HAVING COUNT(*) > 1 LIMIT 1
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot reconcile duplicate system_settings.setting_key values';
        END IF;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'user_storage_quotas'
    ) THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'user_storage_quotas'
              AND column_name = 'id' AND data_type = 'int' AND is_nullable = 'NO'
              AND LOWER(extra) LIKE '%auto_increment%'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy user_storage_quotas.id contract';
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'user_storage_quotas'
              AND column_name = 'user_id' AND column_type = 'int' AND is_nullable = 'NO'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy user_storage_quotas.user_id contract';
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'user_storage_quotas'
              AND column_name = 'quota_bytes' AND column_type = 'bigint unsigned' AND is_nullable = 'NO'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy user_storage_quotas.quota_bytes contract';
        END IF;
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'user_storage_quotas' AND column_name = 'created_at'
        ) AND NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'user_storage_quotas'
              AND column_name = 'created_at' AND data_type = 'datetime'
              AND is_nullable = 'NO' AND LOWER(column_default) = 'current_timestamp'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy user_storage_quotas.created_at contract';
        END IF;
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'user_storage_quotas' AND column_name = 'updated_at'
        ) AND NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'user_storage_quotas'
              AND column_name = 'updated_at' AND data_type = 'datetime'
              AND is_nullable = 'NO' AND LOWER(column_default) = 'current_timestamp'
              AND LOWER(extra) LIKE '%on update current_timestamp%'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy user_storage_quotas.updated_at contract';
        END IF;

        IF EXISTS (
            SELECT user_id FROM user_storage_quotas GROUP BY user_id HAVING COUNT(*) > 1 LIMIT 1
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot reconcile duplicate user_storage_quotas.user_id values';
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'users'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot reconcile user_storage_quotas without users table';
        END IF;
        IF EXISTS (
            SELECT 1
            FROM user_storage_quotas q
            LEFT JOIN users u ON u.id = q.user_id
            WHERE u.id IS NULL
            LIMIT 1
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot reconcile orphan user_storage_quotas rows';
        END IF;
        IF EXISTS (
            SELECT 1
            FROM information_schema.key_column_usage
            WHERE table_schema = DATABASE()
              AND table_name = 'user_storage_quotas'
              AND column_name = 'user_id'
              AND referenced_table_name IS NOT NULL
        ) AND NOT EXISTS (
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
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy user_storage_quotas.user_id foreign key';
        END IF;
    END IF;

    -- All potentially destructive ambiguity checks above have passed. From here
    -- onward only additive columns/indexes/FK and canonical setting metadata are
    -- changed; existing quota values are preserved.
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
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'system_settings'
              AND non_unique = 0 AND column_name = 'setting_key' AND seq_in_index = 1
        ) THEN
            ALTER TABLE `system_settings` ADD UNIQUE KEY `uq_system_settings_key` (`setting_key`);
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'system_settings'
              AND column_name = 'category' AND seq_in_index = 1
        ) THEN
            ALTER TABLE `system_settings` ADD INDEX `idx_system_settings_category` (`category`);
        END IF;

        UPDATE `system_settings`
        SET `setting_type` = 'integer', `category` = 'file_manager', `is_editable` = 1
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
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'user_storage_quotas'
              AND non_unique = 0 AND column_name = 'user_id' AND seq_in_index = 1
        ) THEN
            ALTER TABLE `user_storage_quotas` ADD UNIQUE KEY `uq_user_storage_quota_user` (`user_id`);
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
