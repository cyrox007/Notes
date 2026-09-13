-- Tasks contract for existing installations.
-- Requires canonical users table. Statements are intentionally idempotent.

CREATE TABLE IF NOT EXISTS `tasks` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uid` CHAR(32) NOT NULL,
    `user_id` INT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `status` ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    `priority` ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
    `due_date` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_tasks_uid` (`uid`),
    KEY `idx_tasks_user_active` (`user_id`, `is_deleted`, `created_at`),
    KEY `idx_tasks_user_status` (`user_id`, `is_deleted`, `status`),
    KEY `idx_tasks_user_due` (`user_id`, `is_deleted`, `due_date`),
    CONSTRAINT `fk_tasks_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_categories` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT NULL COMMENT 'NULL means a global/system category',
    `name` VARCHAR(120) NOT NULL,
    `color` CHAR(7) NOT NULL DEFAULT '#3498db',
    `icon` VARCHAR(64) NOT NULL DEFAULT 'fa-folder',
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_task_categories_user` (`user_id`, `is_deleted`, `sort_order`),
    KEY `idx_task_categories_global` (`is_deleted`, `sort_order`),
    CONSTRAINT `fk_task_categories_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_category_relations` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id` BIGINT UNSIGNED NOT NULL,
    `category_id` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_task_category_relation` (`task_id`, `category_id`),
    KEY `idx_task_category_category` (`category_id`, `task_id`),
    CONSTRAINT `fk_task_category_task`
        FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_task_category_category`
        FOREIGN KEY (`category_id`) REFERENCES `task_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subtasks` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `is_completed` TINYINT(1) NOT NULL DEFAULT 0,
    `completed_at` DATETIME DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_subtasks_task_order` (`task_id`, `sort_order`, `id`),
    CONSTRAINT `fk_subtasks_task`
        FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_reminders` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id` BIGINT UNSIGNED NOT NULL,
    `reminder_time` DATETIME NOT NULL,
    `is_sent` TINYINT(1) NOT NULL DEFAULT 0,
    `sent_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_task_reminders_due` (`is_sent`, `reminder_time`),
    KEY `idx_task_reminders_task` (`task_id`, `reminder_time`),
    CONSTRAINT `fk_task_reminders_task`
        FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
