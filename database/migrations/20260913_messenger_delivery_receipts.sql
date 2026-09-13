-- Notes Messenger v2: per-user delivery receipts
-- MySQL 8+. Safe to run on installations already migrated to user_to_dialogs.

DELIMITER $$

DROP PROCEDURE IF EXISTS `migrate_notes_messenger_delivery_receipts`$$
CREATE PROCEDURE `migrate_notes_messenger_delivery_receipts`()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'user_to_dialogs'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'user_to_dialogs'
          AND column_name = 'last_delivered_message_id'
    ) THEN
        ALTER TABLE `user_to_dialogs`
            ADD COLUMN `last_delivered_message_id` BIGINT UNSIGNED NULL AFTER `joined_at`;
    END IF;

    -- A message that was already read was necessarily delivered as well.
    UPDATE `user_to_dialogs`
       SET `last_delivered_message_id` = `last_read_message_id`
     WHERE `last_read_message_id` IS NOT NULL
       AND (
           `last_delivered_message_id` IS NULL
           OR `last_delivered_message_id` < `last_read_message_id`
       );
END$$

DELIMITER ;

CALL `migrate_notes_messenger_delivery_receipts`();
DROP PROCEDURE `migrate_notes_messenger_delivery_receipts`;
