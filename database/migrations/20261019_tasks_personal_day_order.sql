-- Очереди личных задач по дню: версия порядка независима от версии задачи.
-- Повторный запуск безопасен; сохранённые позиции переживают перезагрузку.
CREATE TABLE IF NOT EXISTS `task_personal_day_queues` (
    `user_id` INT NOT NULL,
    `queue_date` DATE NOT NULL,
    `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `queue_date`),
    CONSTRAINT `fk_task_day_queue_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_personal_day_positions` (
    `user_id` INT NOT NULL,
    `queue_date` DATE NOT NULL,
    `task_id` BIGINT UNSIGNED NOT NULL,
    `position` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`user_id`, `queue_date`, `task_id`),
    KEY `idx_task_day_positions_order` (`user_id`, `queue_date`, `position`),
    CONSTRAINT `fk_task_day_position_queue`
        FOREIGN KEY (`user_id`, `queue_date`)
        REFERENCES `task_personal_day_queues` (`user_id`, `queue_date`) ON DELETE CASCADE,
    CONSTRAINT `fk_task_day_position_task`
        FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
