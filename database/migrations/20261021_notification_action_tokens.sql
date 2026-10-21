-- Одноразовые разрешения на действия из внешних уведомлений Notes 1.2.
-- Исходный секрет не хранится; открытие ссылки не выполняет действие.
CREATE TABLE IF NOT EXISTS `notification_action_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_id` INT NOT NULL,
    `notification_id` BIGINT UNSIGNED NOT NULL,
    `action_name` VARCHAR(48) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `consumed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notification_action_token_hash` (`token_hash`),
    KEY `idx_notification_action_token_expiration` (`expires_at`, `consumed_at`),
    KEY `idx_notification_action_token_owner` (`user_id`, `notification_id`),
    CONSTRAINT `fk_notification_action_token_user`
      FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notification_action_token_notification`
      FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
