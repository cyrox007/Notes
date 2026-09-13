-- Messenger membership fields required by current services and later migrations.
-- Run after 20260913_messenger_v2.sql and before delivery receipts.

DELIMITER $$

DROP PROCEDURE IF EXISTS `migrate_notes_messenger_membership_contract`$$
CREATE PROCEDURE `migrate_notes_messenger_membership_contract`()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'user_to_dialogs'
    ) THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'user_to_dialogs' AND column_name = 'joined_at'
        ) THEN
            ALTER TABLE `user_to_dialogs`
                ADD COLUMN `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'user_to_dialogs' AND column_name = 'last_read_message_id'
        ) THEN
            ALTER TABLE `user_to_dialogs`
                ADD COLUMN `last_read_message_id` BIGINT UNSIGNED NULL AFTER `joined_at`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'user_to_dialogs' AND column_name = 'is_deleted'
        ) THEN
            ALTER TABLE `user_to_dialogs`
                ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0;
        END IF;
    END IF;
END$$

DELIMITER ;

CALL `migrate_notes_messenger_membership_contract`();
DROP PROCEDURE `migrate_notes_messenger_membership_contract`;
