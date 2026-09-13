CREATE TABLE IF NOT EXISTS `message_reactions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` BIGINT UNSIGNED NOT NULL,
    `user_id` INT NOT NULL,
    `reaction_code` VARCHAR(24) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_message_reaction_user` (`message_id`, `user_id`, `reaction_code`),
    KEY `idx_message_reaction_message` (`message_id`, `reaction_code`),
    KEY `idx_message_reaction_user` (`user_id`, `message_id`),
    CONSTRAINT `fk_message_reaction_message`
        FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_message_reaction_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
