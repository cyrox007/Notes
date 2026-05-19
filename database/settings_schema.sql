-- ============================================
-- Настройки системы: Структура базы данных
-- Версия: 1.0
-- Описание: Таблица для хранения настроек системы
-- ============================================

-- --------------------------------------------
-- Таблица системных настроек (system_settings)
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Ключ настройки',
    `setting_value` TEXT DEFAULT NULL COMMENT 'Значение настройки',
    `setting_type` ENUM('string', 'number', 'boolean', 'json') NOT NULL DEFAULT 'string' COMMENT 'Тип значения',
    `description` VARCHAR(255) DEFAULT NULL COMMENT 'Описание настройки',
    `category` VARCHAR(50) DEFAULT 'general' COMMENT 'Категория настройки (messenger, file_manager, general)',
    `is_editable` TINYINT(1) DEFAULT 1 COMMENT 'Можно ли редактировать через админку',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_category` (`category`),
    INDEX `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------
-- Начальные настройки для мессенджера
-- --------------------------------------------
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`, `category`, `is_editable`) VALUES
('messenger_max_message_size', '10485760', 'number', 'Максимальный размер сообщения в байтах (по умолчанию 10MB)', 'messenger', 1),
('messenger_allowed_file_types', '["image/jpeg","image/png","image/gif","image/webp","audio/mpeg","audio/ogg","video/mp4","video/webm","application/pdf"]', 'json', 'Разрешенные MIME типы файлов для отправки', 'messenger', 1),
('messenger_max_files_per_message', '5', 'number', 'Максимальное количество файлов в одном сообщении', 'messenger', 1);

-- --------------------------------------------
-- Начальные настройки для файлового менеджера
-- --------------------------------------------
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`, `category`, `is_editable`) VALUES
('file_manager_max_storage_per_user', '1073741824', 'number', 'Максимальный объем хранилища на пользователя в байтах (по умолчанию 1GB)', 'file_manager', 1),
('file_manager_allowed_file_types', '[]', 'json', 'Разрешенные MIME типы файлов (пусто = все разрешены)', 'file_manager', 1),
('file_manager_max_file_size', '104857600', 'number', 'Максимальный размер одного файла в байтах (по умолчанию 100MB)', 'file_manager', 1);

-- --------------------------------------------
-- Таблица квот использования хранилища пользователями
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS `user_storage_quotas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE COMMENT 'ID пользователя',
    `used_storage` BIGINT DEFAULT 0 COMMENT 'Использовано места в байтах',
    `last_calculated_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Время последнего пересчета',
    INDEX `idx_user_id` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

