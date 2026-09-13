-- ============================================================
-- Notes Messenger v2 - canonical schema
-- Fresh-install source of truth for users, dialogs and messages.
-- Existing installations should use database/migrations/20260913_messenger_v2.sql.
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
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_users_uid` (`uid`),
    UNIQUE KEY `uq_users_username` (`username`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dialogs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uid` CHAR(36) NOT NULL,
    `type` ENUM('private', 'group') NOT NULL DEFAULT 'private',
    `name` VARCHAR(120) DEFAULT NULL,
    `avatar` VARCHAR(255) DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_dialogs_uid` (`uid`),
    KEY `idx_dialogs_updated` (`updated_at`),
    KEY `idx_dialogs_creator` (`created_by`),
    CONSTRAINT `fk_dialogs_creator`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_to_dialogs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `dialog_id` BIGINT UNSIGNED NOT NULL,
    `user_id` INT NOT NULL,
    `role` ENUM('owner', 'admin', 'member') NOT NULL DEFAULT 'member',
    `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_read_message_id` BIGINT UNSIGNED DEFAULT NULL,
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `archived_at` DATETIME DEFAULT NULL,
    `muted_until` DATETIME DEFAULT NULL,
    `pinned_at` DATETIME DEFAULT NULL,
    UNIQUE KEY `uq_dialog_member` (`dialog_id`, `user_id`),
    KEY `idx_dialog_member_user` (`user_id`, `is_deleted`),
    KEY `idx_dialog_member_dialog` (`dialog_id`, `is_deleted`),
    CONSTRAINT `fk_dialog_member_dialog`
        FOREIGN KEY (`dialog_id`) REFERENCES `dialogs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_dialog_member_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uid` CHAR(36) NOT NULL,
    `dialog_id` BIGINT UNSIGNED NOT NULL,
    `from_user_id` INT NOT NULL,
    `reply_to_message_id` BIGINT UNSIGNED DEFAULT NULL,
    `message` LONGTEXT NOT NULL COMMENT 'Encrypted versioned payload for textual content',
    `message_type` ENUM('text', 'image', 'audio', 'video', 'file', 'voice', 'service') NOT NULL DEFAULT 'text',
    `media_url` VARCHAR(512) DEFAULT NULL,
    `meta_data` JSON DEFAULT NULL,
    `message_status` ENUM('sent', 'delivered', 'read', 'deleted') NOT NULL DEFAULT 'sent',
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `edited_at` DATETIME DEFAULT NULL,
    `deleted_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_messages_uid` (`uid`),
    KEY `idx_messages_dialog_id` (`dialog_id`, `id`),
    KEY `idx_messages_sender` (`from_user_id`, `created_at`),
    KEY `idx_messages_reply` (`reply_to_message_id`),
    KEY `idx_messages_unread` (`dialog_id`, `is_deleted`, `id`),
    CONSTRAINT `fk_messages_dialog`
        FOREIGN KEY (`dialog_id`) REFERENCES `dialogs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_messages_sender`
        FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_messages_reply`
        FOREIGN KEY (`reply_to_message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Telegram-style "delete only for me" without mutating the message for others.
CREATE TABLE IF NOT EXISTS `message_user_deletions` (
    `message_id` BIGINT UNSIGNED NOT NULL,
    `user_id` INT NOT NULL,
    `deleted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`message_id`, `user_id`),
    KEY `idx_message_user_deletions_user` (`user_id`, `message_id`),
    CONSTRAINT `fk_message_user_deletion_message`
        FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_message_user_deletion_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
