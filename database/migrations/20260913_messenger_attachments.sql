-- Notes Messenger v2: private attachment metadata
-- MySQL 8+. Binary contents are stored outside document root.

CREATE TABLE IF NOT EXISTS `messenger_attachments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uid` CHAR(36) NOT NULL,
    `dialog_id` BIGINT UNSIGNED NOT NULL,
    `uploader_user_id` INT NOT NULL,
    `message_id` BIGINT UNSIGNED DEFAULT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_path` VARCHAR(768) NOT NULL,
    `mime_type` VARCHAR(128) NOT NULL,
    `extension` VARCHAR(16) NOT NULL,
    `media_kind` ENUM('image', 'audio', 'video', 'file', 'voice') NOT NULL DEFAULT 'file',
    `size` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_messenger_attachment_uid` (`uid`),
    KEY `idx_messenger_attachment_dialog` (`dialog_id`, `created_at`),
    KEY `idx_messenger_attachment_message` (`message_id`),
    KEY `idx_messenger_attachment_uploader` (`uploader_user_id`, `created_at`),
    CONSTRAINT `fk_messenger_attachment_dialog`
        FOREIGN KEY (`dialog_id`) REFERENCES `dialogs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_messenger_attachment_uploader`
        FOREIGN KEY (`uploader_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_messenger_attachment_message`
        FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
