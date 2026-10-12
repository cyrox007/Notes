-- Calendar 1.1: ежедневник, временные блоки и безопасные межмодульные связи.

CREATE TABLE IF NOT EXISTS `calendar_time_blocks` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uid` CHAR(36) NOT NULL,
    `owner_user_id` INT NOT NULL,
    `title` VARCHAR(190) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `location` VARCHAR(255) DEFAULT NULL,
    `timezone` VARCHAR(64) NOT NULL DEFAULT 'UTC',
    `starts_at_utc` DATETIME NOT NULL,
    `ends_at_utc` DATETIME NOT NULL,
    `status` ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_calendar_time_blocks_uid` (`uid`),
    KEY `idx_calendar_time_blocks_owner_time` (`owner_user_id`, `status`, `starts_at_utc`, `ends_at_utc`),
    CONSTRAINT `fk_calendar_time_blocks_owner`
        FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `calendar_item_links` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `owner_user_id` INT NOT NULL,
    `source_type` ENUM('event','time_block') NOT NULL,
    `source_uid` CHAR(36) NOT NULL,
    `target_type` ENUM('task','note','file') NOT NULL,
    `target_uid` VARCHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_calendar_item_link` (`owner_user_id`, `source_type`, `source_uid`, `target_type`, `target_uid`),
    KEY `idx_calendar_item_links_source` (`owner_user_id`, `source_type`, `source_uid`, `id`),
    KEY `idx_calendar_item_links_target` (`target_type`, `target_uid`),
    CONSTRAINT `fk_calendar_item_links_owner`
        FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
