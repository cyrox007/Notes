-- ============================================
-- Пользовательские поля (User Fields): Структура базы данных
-- Версия: 1.0
-- Описание: Таблица для хранения настраиваемых полей профиля пользователя
-- ============================================

-- --------------------------------------------
-- Таблица пользовательских полей (user_fields)
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS `user_fields` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `field_name` VARCHAR(50) NOT NULL COMMENT 'Имя поля (техническое)',
    `field_type` ENUM('text', 'textarea', 'number', 'date', 'select', 'checkbox') NOT NULL DEFAULT 'text' COMMENT 'Тип поля',
    `field_label` VARCHAR(100) NOT NULL COMMENT 'Отображаемое название поля',
    `is_required` TINYINT(1) DEFAULT 0 COMMENT 'Обязательное поле',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_field_name` (`field_name`),
    INDEX `idx_field_type` (`field_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Настраиваемые поля профиля пользователя';

-- --------------------------------------------
-- Начальные данные для пользовательских полей
-- --------------------------------------------
INSERT INTO `user_fields` (`field_name`, `field_type`, `field_label`, `is_required`) VALUES
('city', 'text', 'Город', 0),
('company', 'text', 'Компания', 0),
('position', 'text', 'Должность', 0),
('website', 'text', 'Веб-сайт', 0),
('bio', 'textarea', 'О себе', 0);
