-- Workspace Organizer 0.14 Beta 4 shared task boards.
-- Additive schema kept separate from legacy personal tasks so existing task data
-- and routes remain backward compatible.

CREATE TABLE IF NOT EXISTS `task_boards` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uid` CHAR(32) NOT NULL,
    `owner_user_id` INT NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `audience` ENUM('members','all_active') NOT NULL DEFAULT 'members',
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_task_boards_uid` (`uid`),
    KEY `idx_task_boards_owner` (`owner_user_id`,`is_archived`,`updated_at`),
    KEY `idx_task_boards_audience` (`audience`,`is_archived`,`updated_at`),
    CONSTRAINT `fk_task_boards_owner`
        FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_board_members` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `board_id` BIGINT UNSIGNED NOT NULL,
    `user_id` INT NOT NULL,
    `role` ENUM('manager','member','viewer') NOT NULL DEFAULT 'member',
    `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_task_board_member` (`board_id`,`user_id`),
    KEY `idx_task_board_members_user` (`user_id`,`board_id`),
    CONSTRAINT `fk_task_board_members_board`
        FOREIGN KEY (`board_id`) REFERENCES `task_boards` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_task_board_members_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_board_items` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uid` CHAR(32) NOT NULL,
    `board_id` BIGINT UNSIGNED NOT NULL,
    `creator_user_id` INT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `status` ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
    `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    `due_date` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_task_board_items_uid` (`uid`),
    KEY `idx_task_board_items_board` (`board_id`,`is_deleted`,`status`,`updated_at`),
    KEY `idx_task_board_items_creator` (`creator_user_id`,`is_deleted`),
    CONSTRAINT `fk_task_board_items_board`
        FOREIGN KEY (`board_id`) REFERENCES `task_boards` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_task_board_items_creator`
        FOREIGN KEY (`creator_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_board_assignees` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id` BIGINT UNSIGNED NOT NULL,
    `user_id` INT NOT NULL,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_task_board_assignee` (`task_id`,`user_id`),
    KEY `idx_task_board_assignees_user` (`user_id`,`task_id`),
    CONSTRAINT `fk_task_board_assignees_task`
        FOREIGN KEY (`task_id`) REFERENCES `task_board_items` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_task_board_assignees_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
