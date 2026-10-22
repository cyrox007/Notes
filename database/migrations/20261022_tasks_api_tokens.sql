-- Notes 1.2: ключи внешнего чтения задач, привязанные к пользователю.
-- Храним только SHA-256 секрета; исходный ключ выдаётся один раз.
CREATE TABLE IF NOT EXISTS `task_api_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `scope` VARCHAR(32) NOT NULL DEFAULT 'tasks.read',
    `expires_at` DATETIME NOT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_task_api_token_hash` (`token_hash`),
    KEY `idx_task_api_token_owner` (`user_id`,`revoked_at`,`expires_at`),
    CONSTRAINT `fk_task_api_token_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
