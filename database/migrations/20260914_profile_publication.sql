-- 0.13: explicit public-profile publication contract.
-- Existing objects remain private by default. Share tokens are not migrated into
-- profile publication, and private file paths/content are never exposed by this flag.

DELIMITER //
CREATE PROCEDURE `workspace_add_profile_publication`()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'notes'
    ) THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'notes' AND column_name = 'is_profile_public'
        ) THEN
            ALTER TABLE `notes`
                ADD COLUMN `is_profile_public` TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'Явно опубликовано владельцем в публичном профиле'
                AFTER `is_deleted`;
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'notes' AND index_name = 'idx_notes_profile_public'
        ) THEN
            ALTER TABLE `notes`
                ADD INDEX `idx_notes_profile_public` (`user_id`, `is_profile_public`, `is_deleted`, `updated_note` DESC);
        END IF;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'tasks'
    ) THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'tasks' AND column_name = 'is_profile_public'
        ) THEN
            ALTER TABLE `tasks`
                ADD COLUMN `is_profile_public` TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'Явно опубликовано владельцем в публичном профиле'
                AFTER `is_deleted`;
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'tasks' AND index_name = 'idx_tasks_profile_public'
        ) THEN
            ALTER TABLE `tasks`
                ADD INDEX `idx_tasks_profile_public` (`user_id`, `is_profile_public`, `is_deleted`, `updated_at`);
        END IF;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'user_files'
    ) THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'user_files' AND column_name = 'is_profile_public'
        ) THEN
            ALTER TABLE `user_files`
                ADD COLUMN `is_profile_public` TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'Явно опубликовано владельцем в публичном профиле'
                AFTER `is_deleted`;
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'user_files' AND index_name = 'idx_files_profile_public'
        ) THEN
            ALTER TABLE `user_files`
                ADD INDEX `idx_files_profile_public` (`user_id`, `is_profile_public`, `is_deleted`, `updated_at`);
        END IF;
    END IF;
END//
CALL `workspace_add_profile_publication`()//
DROP PROCEDURE `workspace_add_profile_publication`//
DELIMITER ;
