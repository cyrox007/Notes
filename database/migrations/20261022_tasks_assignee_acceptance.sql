-- Подтверждение принятия командной задачи назначенным исполнителем.
ALTER TABLE `task_board_items`
    ADD COLUMN `accepted_by_user_id` INT NULL DEFAULT NULL AFTER `completed_at`,
    ADD COLUMN `accepted_at` DATETIME NULL DEFAULT NULL AFTER `accepted_by_user_id`,
    ADD KEY `idx_task_board_items_acceptance` (`accepted_by_user_id`, `accepted_at`),
    ADD CONSTRAINT `fk_task_board_items_accepted_user` FOREIGN KEY (`accepted_by_user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL;
