-- ============================================================
-- Workspace Organizer core identity schema
-- Fresh-install source of truth for the platform users table.
-- Product modules may reference users but do not own it.
-- ============================================================

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `uid` CHAR(36) NOT NULL,
    `username` VARCHAR(50) NOT NULL,
    `email` VARCHAR(190) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `firstname` VARCHAR(80) NOT NULL DEFAULT '',
    `patronymic` VARCHAR(80) DEFAULT NULL,
    `lastname` VARCHAR(80) NOT NULL DEFAULT '',
    `phone` VARCHAR(32) DEFAULT NULL,
    `avatar` VARCHAR(255) DEFAULT NULL,
    `property` JSON DEFAULT NULL,
    `role` INT NOT NULL DEFAULT 888,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `account_status` ENUM('active', 'inactive', 'blocked') NOT NULL DEFAULT 'active',
    `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `totp_secret` TEXT DEFAULT NULL,
    `totp_last_counter` BIGINT UNSIGNED DEFAULT NULL,
    `totp_recovery_codes` JSON DEFAULT NULL,
    `totp_confirmed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_users_uid` (`uid`),
    UNIQUE KEY `uq_users_username` (`username`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
