-- ============================================
-- Файловый менеджер: Структура базы данных
-- Версия: 1.0
-- Описание: Таблицы для хранения личных файлов пользователей
-- ============================================

-- --------------------------------------------
-- Таблица пользовательских файлов и папок (user_files)
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS `user_files` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `uid` VARCHAR(64) UNIQUE DEFAULT NULL COMMENT 'Уникальный идентификатор файла',
    `user_id` INT NOT NULL COMMENT 'Владелец файла',
    `parent_id` INT DEFAULT NULL COMMENT 'ID родительской папки (NULL = корень)',
    `name` VARCHAR(255) NOT NULL COMMENT 'Имя файла или папки',
    `type` ENUM('file', 'folder') NOT NULL DEFAULT 'file' COMMENT 'Тип: файл или папка',
    `mime_type` VARCHAR(100) DEFAULT NULL COMMENT 'MIME тип файла',
    `size` BIGINT DEFAULT 0 COMMENT 'Размер файла в байтах',
    `path` VARCHAR(500) DEFAULT NULL COMMENT 'Путь к файлу на сервере',
    `extension` VARCHAR(20) DEFAULT NULL COMMENT 'Расширение файла',
    `is_deleted` TINYINT(1) DEFAULT 0 COMMENT 'Safe delete флаг',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_parent_id` (`parent_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_is_deleted` (`is_deleted`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`parent_id`) REFERENCES `user_files`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------
-- Примечания по безопасности
-- --------------------------------------------
--
-- 1. Файлы хранятся вне корневой директории веб-сервера
-- 2. Доступ к файлам только через контроллер с проверкой прав
-- 3. Для исполняемых файлов (скриптов) предусмотрена песочница
-- 4. Safe-удаление: файлы помечаются флагом is_deleted
--
