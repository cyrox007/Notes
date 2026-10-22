-- Notes 1.2: атомарный минутный лимит запросов на каждый ключ Tasks.
CREATE TABLE IF NOT EXISTS `task_api_rate_buckets` (
    `token_id` BIGINT UNSIGNED NOT NULL,
    `window_start` DATETIME NOT NULL,
    `requests` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`token_id`, `window_start`),
    CONSTRAINT `fk_task_api_rate_token` FOREIGN KEY (`token_id`)
        REFERENCES `task_api_tokens` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал содержит только идентификатор ключа, операцию и итог; секреты исключены.
CREATE TABLE IF NOT EXISTS `task_api_access_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token_id` BIGINT UNSIGNED NOT NULL,
    `user_id` INT NOT NULL,
    `action` VARCHAR(32) NOT NULL,
    `status_code` SMALLINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_task_api_log_owner` (`user_id`, `created_at`),
    KEY `idx_task_api_log_token` (`token_id`, `created_at`),
    CONSTRAINT `fk_task_api_log_token` FOREIGN KEY (`token_id`)
        REFERENCES `task_api_tokens` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_task_api_log_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
