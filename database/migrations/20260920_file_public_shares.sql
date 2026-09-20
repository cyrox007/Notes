-- Public, revocable links for File Manager objects.
-- Tokens are random and only the token hash is indexed by the public endpoint.

CREATE TABLE IF NOT EXISTS `file_shares` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_id` INT NOT NULL,
    `owner_user_id` INT NOT NULL,
    `share_token` CHAR(64) NOT NULL,
    `expires_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_file_shares_token` (`share_token`),
    KEY `idx_file_shares_owner_file` (`owner_user_id`, `file_id`, `is_active`),
    KEY `idx_file_shares_expiry` (`is_active`, `expires_at`),
    CONSTRAINT `fk_file_shares_file`
        FOREIGN KEY (`file_id`) REFERENCES `user_files` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_file_shares_owner`
        FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
