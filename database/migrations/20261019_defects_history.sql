-- Предметная история модуля «Дефекты».
-- События не удаляются при архивировании справочников или закрытии дефекта.
-- Не используем полиморфный внешний ключ: сохраняем историю и идентификатор
-- автора даже после изменения жизненного цикла пользовательского аккаунта.
CREATE TABLE IF NOT EXISTS `defect_history` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `entity_type` VARCHAR(16) NOT NULL,
    `entity_id` BIGINT UNSIGNED NOT NULL,
    `event_type` VARCHAR(24) NOT NULL,
    `actor_user_id` INT DEFAULT NULL,
    `command_id` VARCHAR(96) DEFAULT NULL,
    `payload_json` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL,
    KEY `idx_defect_history_entity` (`entity_type`, `entity_id`, `id`),
    KEY `idx_defect_history_created` (`created_at`, `id`),
    UNIQUE KEY `uq_defect_history_command` (`entity_type`, `entity_id`, `event_type`, `command_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
