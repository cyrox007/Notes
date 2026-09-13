-- Notes Messenger v2 migration
-- Target: MySQL 8+, existing databases created from the repository's legacy messenger schema.
-- Take a backup before applying this migration.

DELIMITER $$

DROP PROCEDURE IF EXISTS `migrate_notes_messenger_v2`$$
CREATE PROCEDURE `migrate_notes_messenger_v2`()
BEGIN
    -- Membership table name used by the application.
    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'dialog_users'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'user_to_dialogs'
    ) THEN
        RENAME TABLE `dialog_users` TO `user_to_dialogs`;
    END IF;

    -- Canonical user identity/profile columns.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'uid'
    ) THEN
        ALTER TABLE `users` ADD COLUMN `uid` CHAR(36) NULL AFTER `id`;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'surname'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'lastname'
    ) THEN
        ALTER TABLE `users` RENAME COLUMN `surname` TO `lastname`;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'user_image'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'avatar'
    ) THEN
        ALTER TABLE `users` RENAME COLUMN `user_image` TO `avatar`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'patronymic'
    ) THEN
        ALTER TABLE `users` ADD COLUMN `patronymic` VARCHAR(80) NULL AFTER `firstname`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'phone'
    ) THEN
        ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(32) NULL AFTER `lastname`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'avatar'
    ) THEN
        ALTER TABLE `users` ADD COLUMN `avatar` VARCHAR(255) NULL AFTER `phone`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'property'
    ) THEN
        ALTER TABLE `users` ADD COLUMN `property` JSON NULL AFTER `avatar`;
    END IF;

    UPDATE `users` SET `uid` = UUID() WHERE `uid` IS NULL OR `uid` = '';
    ALTER TABLE `users` MODIFY COLUMN `uid` CHAR(36) NOT NULL;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'uq_users_uid'
    ) THEN
        ALTER TABLE `users` ADD UNIQUE KEY `uq_users_uid` (`uid`);
    END IF;

    -- Dialog/member capabilities used by Telegram-like UI.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'dialogs' AND column_name = 'avatar'
    ) THEN
        ALTER TABLE `dialogs` ADD COLUMN `avatar` VARCHAR(255) NULL AFTER `name`;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'user_to_dialogs'
    ) THEN
        ALTER TABLE `user_to_dialogs`
            MODIFY COLUMN `role` ENUM('owner','admin','member') NOT NULL DEFAULT 'member';

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'user_to_dialogs' AND column_name = 'archived_at'
        ) THEN
            ALTER TABLE `user_to_dialogs` ADD COLUMN `archived_at` DATETIME NULL;
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'user_to_dialogs' AND column_name = 'muted_until'
        ) THEN
            ALTER TABLE `user_to_dialogs` ADD COLUMN `muted_until` DATETIME NULL;
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'user_to_dialogs' AND column_name = 'pinned_at'
        ) THEN
            ALTER TABLE `user_to_dialogs` ADD COLUMN `pinned_at` DATETIME NULL;
        END IF;
    END IF;

    -- Message field names: schema and PHP now use one contract.
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'messages' AND column_name = 'sender_id'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'messages' AND column_name = 'from_user_id'
    ) THEN
        ALTER TABLE `messages` RENAME COLUMN `sender_id` TO `from_user_id`;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'messages' AND column_name = 'content'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'messages' AND column_name = 'message'
    ) THEN
        ALTER TABLE `messages` RENAME COLUMN `content` TO `message`;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'messages' AND column_name = 'content_type'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'messages' AND column_name = 'message_type'
    ) THEN
        ALTER TABLE `messages` RENAME COLUMN `content_type` TO `message_type`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'messages' AND column_name = 'reply_to_message_id'
    ) THEN
        ALTER TABLE `messages` ADD COLUMN `reply_to_message_id` BIGINT NULL AFTER `from_user_id`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'messages' AND column_name = 'media_url'
    ) THEN
        ALTER TABLE `messages` ADD COLUMN `media_url` VARCHAR(512) NULL AFTER `message_type`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'messages' AND column_name = 'meta_data'
    ) THEN
        ALTER TABLE `messages` ADD COLUMN `meta_data` JSON NULL AFTER `media_url`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'messages' AND column_name = 'edited_at'
    ) THEN
        ALTER TABLE `messages` ADD COLUMN `edited_at` DATETIME NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'messages' AND column_name = 'deleted_at'
    ) THEN
        ALTER TABLE `messages` ADD COLUMN `deleted_at` DATETIME NULL;
    END IF;

    UPDATE `messages` SET `message_status` = 'sent' WHERE `message_status` = 'unread';
    ALTER TABLE `messages`
        MODIFY COLUMN `message_type` ENUM('text','image','audio','video','file','voice','service') NOT NULL DEFAULT 'text',
        MODIFY COLUMN `message_status` ENUM('sent','delivered','read','deleted') NOT NULL DEFAULT 'sent';

    -- Per-user deletion is intentionally separate from global soft-delete.
    CREATE TABLE IF NOT EXISTS `message_user_deletions` (
        `message_id` BIGINT NOT NULL,
        `user_id` BIGINT NOT NULL,
        `deleted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`message_id`, `user_id`),
        KEY `idx_message_user_deletions_user` (`user_id`, `message_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
END$$

DELIMITER ;

CALL `migrate_notes_messenger_v2`();
DROP PROCEDURE `migrate_notes_messenger_v2`;
