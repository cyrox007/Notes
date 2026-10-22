-- Без явного согласия утренние уведомления не формируются.
CREATE TABLE IF NOT EXISTS `task_morning_digest_settings` (
    `user_id` INT NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_task_digest_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
