-- Диагностика фонового цикла содержит только обезличенные счётчики.
-- Незавершённый статус running помогает обнаружить аварийное прерывание.
CREATE TABLE IF NOT EXISTS `task_reminder_cycle_runs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `started_at` DATETIME NOT NULL,
    `finished_at` DATETIME NULL,
    `status` VARCHAR(16) NOT NULL,
    `morning_published` INT UNSIGNED NOT NULL DEFAULT 0,
    `personal_published` INT UNSIGNED NOT NULL DEFAULT 0,
    `board_published` INT UNSIGNED NOT NULL DEFAULT 0,
    `delivery_claimed` INT UNSIGNED NOT NULL DEFAULT 0,
    `delivery_sent` INT UNSIGNED NOT NULL DEFAULT 0,
    `delivery_retry` INT UNSIGNED NOT NULL DEFAULT 0,
    `delivery_failed` INT UNSIGNED NOT NULL DEFAULT 0,
    `error_stage` VARCHAR(80) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_task_cycle_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
