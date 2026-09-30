-- Короткоживущая активность Messenger для WebSocket и HTTP Long Poll.
-- Строки живут несколько секунд и автоматически очищаются сервисом.

CREATE TABLE IF NOT EXISTS `messenger_activity` (
    `dialog_id` BIGINT UNSIGNED NOT NULL,
    `user_id` INT NOT NULL,
    `activity` VARCHAR(32) NOT NULL,
    `expires_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`dialog_id`, `user_id`, `activity`),
    KEY `idx_messenger_activity_expiry` (`expires_at`),
    KEY `idx_messenger_activity_user` (`user_id`, `expires_at`),
    CONSTRAINT `fk_messenger_activity_dialog`
        FOREIGN KEY (`dialog_id`) REFERENCES `dialogs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_messenger_activity_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
