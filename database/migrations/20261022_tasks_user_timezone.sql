-- Личная календарная зона для очереди задач.
-- Старые DATETIME не переписываются; при отсутствии настройки используется зона приложения.
CREATE TABLE IF NOT EXISTS `task_user_preferences` (
    `user_id` INT NOT NULL,
    `timezone` VARCHAR(64) NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_task_preferences_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
