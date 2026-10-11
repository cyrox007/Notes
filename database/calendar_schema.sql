-- ============================================================
-- Workspace Organizer Calendar — события, повторения и напоминания 1.1
-- Все моменты времени хранятся в UTC. Часовой пояс события хранится
-- как IANA-идентификатор и используется для локального времени и повторений.
-- ============================================================

CREATE TABLE IF NOT EXISTS `calendar_events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uid` CHAR(36) NOT NULL,
    `owner_user_id` INT NOT NULL,
    `title` VARCHAR(190) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `location` VARCHAR(255) DEFAULT NULL,
    `is_all_day` TINYINT(1) NOT NULL DEFAULT 0,
    `timezone` VARCHAR(64) NOT NULL DEFAULT 'UTC',
    `starts_at_utc` DATETIME NOT NULL,
    `ends_at_utc` DATETIME DEFAULT NULL,
    `recurrence_rule` VARCHAR(512) DEFAULT NULL,
    `status` ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_calendar_events_uid` (`uid`),
    KEY `idx_calendar_events_owner_time` (`owner_user_id`, `status`, `starts_at_utc`),
    CONSTRAINT `fk_calendar_events_owner`
        FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `calendar_event_reminder_offsets` (
    `event_id` BIGINT UNSIGNED NOT NULL,
    `offset_seconds` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`event_id`, `offset_seconds`),
    CONSTRAINT `fk_calendar_event_reminder_offsets_event`
        FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `calendar_reminders` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_id` BIGINT UNSIGNED NOT NULL,
    `occurrence_start_utc` DATETIME NOT NULL,
    `remind_at_utc` DATETIME NOT NULL,
    `offset_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
    `state` ENUM('pending','published','expired') NOT NULL DEFAULT 'pending',
    `publish_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `last_attempt_at` DATETIME DEFAULT NULL,
    `last_error_code` VARCHAR(80) DEFAULT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `expired_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_calendar_reminder_occurrence` (`event_id`, `occurrence_start_utc`, `offset_seconds`),
    KEY `idx_calendar_reminders_due` (`state`, `remind_at_utc`, `id`),
    KEY `idx_calendar_reminders_event` (`event_id`, `occurrence_start_utc`),
    CONSTRAINT `fk_calendar_reminders_event`
        FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `calendar_runtime_state` (
    `state_key` VARCHAR(64) NOT NULL PRIMARY KEY,
    `last_http_run_at` DATETIME DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `calendar_runtime_state` (`state_key`, `last_http_run_at`)
VALUES ('reminder_http_fallback', NULL)
ON DUPLICATE KEY UPDATE `state_key` = VALUES(`state_key`);
