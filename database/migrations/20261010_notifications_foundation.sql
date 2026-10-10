-- Базовый платформенный слой уведомлений Workspace Organizer 1.1.0.
-- Событие хранится отдельно от состояния конкретного получателя.
-- Внешняя доставка отделена от встроенного центра уведомлений и может
-- обрабатываться cron, планировщиком или ограниченным резервным проходом.

CREATE TABLE IF NOT EXISTS `notification_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_name` VARCHAR(120) NOT NULL,
    `event_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `idempotency_key` VARCHAR(190) NOT NULL,
    `source_name` VARCHAR(80) NOT NULL,
    `category` VARCHAR(80) NOT NULL,
    `importance` VARCHAR(16) NOT NULL DEFAULT 'normal',
    `occurred_at` DATETIME NOT NULL,
    `payload_json` JSON NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notification_event_idempotency` (`event_name`, `idempotency_key`),
    KEY `idx_notification_events_created` (`created_at`),
    KEY `idx_notification_events_category` (`category`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_id` BIGINT UNSIGNED NOT NULL,
    `recipient_user_id` INT NOT NULL,
    `title` VARCHAR(190) NOT NULL,
    `body` TEXT NOT NULL,
    `target_path` VARCHAR(500) DEFAULT NULL,
    `read_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notification_recipient` (`event_id`, `recipient_user_id`),
    KEY `idx_notifications_recipient_unread` (`recipient_user_id`, `read_at`, `created_at`),
    KEY `idx_notifications_event` (`event_id`),
    CONSTRAINT `fk_notifications_event`
        FOREIGN KEY (`event_id`) REFERENCES `notification_events` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notifications_user`
        FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_subscriptions` (
    `user_id` INT NOT NULL,
    `category` VARCHAR(80) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `in_app_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `email_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `browser_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `telegram_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `webhook_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `sms_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `category`),
    KEY `idx_notification_subscriptions_category` (`category`, `enabled`),
    CONSTRAINT `fk_notification_subscriptions_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_channel_configs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `channel` VARCHAR(32) NOT NULL,
    `owner_user_id` INT DEFAULT NULL,
    `label` VARCHAR(120) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `config_json` JSON NOT NULL,
    `secret_ref` VARCHAR(190) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notification_channel_config` (`channel`, `owner_user_id`, `label`),
    KEY `idx_notification_channel_configs_owner` (`owner_user_id`, `enabled`),
    CONSTRAINT `fk_notification_channel_config_user`
        FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_delivery_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `notification_id` BIGINT UNSIGNED NOT NULL,
    `channel_config_id` BIGINT UNSIGNED NOT NULL,
    `channel` VARCHAR(32) NOT NULL,
    `recipient_ref` VARCHAR(190) NOT NULL,
    `state` VARCHAR(16) NOT NULL DEFAULT 'pending',
    `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    `next_attempt_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `locked_at` DATETIME DEFAULT NULL,
    `locked_by` VARCHAR(80) DEFAULT NULL,
    `last_result_code` VARCHAR(80) DEFAULT NULL,
    `sent_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notification_delivery_job` (`notification_id`, `channel_config_id`),
    KEY `idx_notification_delivery_ready` (`state`, `next_attempt_at`, `id`),
    KEY `idx_notification_delivery_lock` (`locked_at`, `state`),
    CONSTRAINT `fk_notification_delivery_notification`
        FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notification_delivery_config`
        FOREIGN KEY (`channel_config_id`) REFERENCES `notification_channel_configs` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_delivery_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_id` BIGINT UNSIGNED NOT NULL,
    `attempt_no` SMALLINT UNSIGNED NOT NULL,
    `result_state` VARCHAR(16) NOT NULL,
    `result_code` VARCHAR(80) DEFAULT NULL,
    `error_summary` VARCHAR(500) DEFAULT NULL,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notification_delivery_attempt` (`job_id`, `attempt_no`),
    KEY `idx_notification_delivery_attempted` (`attempted_at`),
    CONSTRAINT `fk_notification_delivery_attempt_job`
        FOREIGN KEY (`job_id`) REFERENCES `notification_delivery_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
