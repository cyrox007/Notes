-- Workspace Organizer Messenger transport journal
-- Durable short-lived event stream shared by native WebSocket and HTTP long-poll.

CREATE TABLE IF NOT EXISTS `messenger_transport_events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NOT NULL,
    KEY `idx_messenger_transport_user_cursor` (`user_id`, `id`),
    KEY `idx_messenger_transport_expiry` (`expires_at`),
    CONSTRAINT `fk_messenger_transport_event_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
