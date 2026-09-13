-- Canonical user contract required by Messenger v2.
-- Safe to run after the main messenger migration on MySQL 8+.

DELIMITER $$

DROP PROCEDURE IF EXISTS `migrate_notes_user_contract_v2`$$
CREATE PROCEDURE `migrate_notes_user_contract_v2`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'password_hash'
    ) AND EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'password'
    ) THEN
        ALTER TABLE `users` RENAME COLUMN `password` TO `password_hash`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'lastname'
    ) THEN
        ALTER TABLE `users` ADD COLUMN `lastname` VARCHAR(80) NOT NULL DEFAULT '' AFTER `patronymic`;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'surname'
    ) THEN
        UPDATE `users` SET `lastname` = `surname` WHERE `lastname` = '' AND `surname` IS NOT NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'avatar'
    ) THEN
        ALTER TABLE `users` ADD COLUMN `avatar` VARCHAR(255) NULL AFTER `phone`;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'user_image'
    ) THEN
        UPDATE `users` SET `avatar` = `user_image` WHERE (`avatar` IS NULL OR `avatar` = '') AND `user_image` IS NOT NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'is_active'
    ) THEN
        ALTER TABLE `users` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `role`;
        UPDATE `users` SET `is_active` = CASE WHEN `role` IN (899, 999) THEN 0 ELSE 1 END;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'updated_at'
    ) THEN
        ALTER TABLE `users` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
    END IF;
END$$

DELIMITER ;

CALL `migrate_notes_user_contract_v2`();
DROP PROCEDURE `migrate_notes_user_contract_v2`;
