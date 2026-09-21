-- RFC 6238 compatible TOTP two-factor authentication state.
-- Secrets are encrypted by the application before storage; recovery codes are
-- stored only as one-way SHA-256 hashes of high-entropy random values.

DELIMITER $$

DROP PROCEDURE IF EXISTS `migrate_notes_totp_two_factor`$$
CREATE PROCEDURE `migrate_notes_totp_two_factor`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'totp_enabled'
    ) THEN
        ALTER TABLE `users`
            ADD COLUMN `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `account_status`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'totp_secret'
    ) THEN
        ALTER TABLE `users`
            ADD COLUMN `totp_secret` TEXT NULL AFTER `totp_enabled`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'totp_last_counter'
    ) THEN
        ALTER TABLE `users`
            ADD COLUMN `totp_last_counter` BIGINT UNSIGNED NULL AFTER `totp_secret`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'totp_recovery_codes'
    ) THEN
        ALTER TABLE `users`
            ADD COLUMN `totp_recovery_codes` JSON NULL AFTER `totp_last_counter`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'totp_confirmed_at'
    ) THEN
        ALTER TABLE `users`
            ADD COLUMN `totp_confirmed_at` DATETIME NULL AFTER `totp_recovery_codes`;
    END IF;
END$$

DELIMITER ;

CALL `migrate_notes_totp_two_factor`();
DROP PROCEDURE `migrate_notes_totp_two_factor`;
