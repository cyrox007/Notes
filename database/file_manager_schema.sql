-- ============================================
-- Legacy File Manager compatibility aggregate.
--
-- New composition-aware installs MUST use database/file_manager_module_schema.sql
-- plus database/file_storage_quota_schema.sql as declared by modules/files/module.json.
-- This aggregate remains only for pre-1.0 tooling and test fixtures that historically
-- imported one File Manager schema file. Its Core settings bridge is compatibility
-- behavior and is intentionally outside the new ownership graph.
-- ============================================

CREATE TABLE IF NOT EXISTS `user_files` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `uid` VARCHAR(64) UNIQUE DEFAULT NULL COMMENT 'Уникальный идентификатор файла',
    `user_id` INT NOT NULL COMMENT 'Владелец файла',
    `parent_id` INT DEFAULT NULL COMMENT 'ID родительской папки (NULL = корень)',
    `name` VARCHAR(255) NOT NULL COMMENT 'Имя файла или папки',
    `type` VARCHAR(50) NOT NULL DEFAULT 'file' COMMENT 'Тип: folder, file, document, image, video, audio, archive',
    `mime_type` VARCHAR(100) DEFAULT NULL COMMENT 'MIME тип файла',
    `size` BIGINT DEFAULT 0 COMMENT 'Размер файла в байтах',
    `path` VARCHAR(500) DEFAULT NULL COMMENT 'Путь к файлу на сервере',
    `extension` VARCHAR(20) DEFAULT NULL COMMENT 'Расширение файла',
    `is_deleted` TINYINT(1) DEFAULT 0 COMMENT 'Safe delete флаг',
    `is_profile_public` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Явно опубликовано владельцем в публичном профиле',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_parent_id` (`parent_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_is_deleted` (`is_deleted`),
    INDEX `idx_files_profile_public` (`user_id`, `is_profile_public`, `is_deleted`, `updated_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`parent_id`) REFERENCES `user_files`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NOT NULL,
    `setting_type` ENUM('string','integer','boolean','json') NOT NULL DEFAULT 'string',
    `category` VARCHAR(50) NOT NULL DEFAULT 'general',
    `description` VARCHAR(255) DEFAULT NULL,
    `is_editable` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_system_settings_key` (`setting_key`),
    INDEX `idx_system_settings_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_storage_quotas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `quota_bytes` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_storage_quota_user` (`user_id`),
    CONSTRAINT `fk_user_storage_quota_user`
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings`
    (`setting_key`,`setting_value`,`setting_type`,`category`,`description`,`is_editable`)
VALUES
    ('file_manager_default_quota_bytes','1073741824','integer','file_manager','Default File Manager storage quota per user in bytes',1)
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

-- --------------------------------------------
-- Примечания по безопасности
-- --------------------------------------------
-- 1. Новые файлы хранятся вне document root (PRIVATE_STORAGE_PATH).
-- 2. Доступ к файлам выполняется только через контроллер с ownership-проверкой.
-- 3. Загрузка использует allowlist расширений + MIME и лимит размера.
-- 4. Исполняемые и активные web-форматы (php/html/js/svg и т.п.) не принимаются.
-- 5. Старые записи из uploads/file_manager поддерживаются только для миграции/чтения.
-- 6. Safe-delete: запись помечается is_deleted; физический файл удаляется контроллером.
-- 7. is_profile_public по умолчанию 0 и разрешает только безопасную карточку метаданных
--    в профиле; внутренний path и private-storage URL никогда не публикуются автоматически.
