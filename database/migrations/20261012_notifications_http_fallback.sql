-- Состояние ограниченного HTTP-прохода внешних уведомлений для shared-hosting.
-- Таблица создаётся только миграцией: runtime не должен менять схему на запросах пользователей.
CREATE TABLE IF NOT EXISTS `notification_runtime_state` (
    `state_key` VARCHAR(80) NOT NULL,
    `last_http_run_at` DATETIME NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`state_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
