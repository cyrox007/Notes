-- ============================================
-- File Manager canonical module schema
-- Fresh-install source of truth for user_files.
-- Requires the core `users` table from database/core_identity_schema.sql.
-- Quota ownership is defined separately in database/file_storage_quota_schema.sql.
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


CREATE TABLE IF NOT EXISTS `file_shares` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_id` INT NOT NULL,
    `owner_user_id` INT NOT NULL,
    `share_token` CHAR(64) NOT NULL,
    `expires_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_file_shares_token` (`share_token`),
    KEY `idx_file_shares_owner_file` (`owner_user_id`, `file_id`, `is_active`),
    KEY `idx_file_shares_expiry` (`is_active`, `expires_at`),
    CONSTRAINT `fk_file_shares_file`
        FOREIGN KEY (`file_id`) REFERENCES `user_files` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_file_shares_owner`
        FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
