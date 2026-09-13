-- User profile custom-field contract for existing installations.

CREATE TABLE IF NOT EXISTS `user_fields` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `field_name` VARCHAR(50) NOT NULL,
    `field_type` ENUM('text', 'textarea', 'number', 'date', 'select', 'checkbox') NOT NULL DEFAULT 'text',
    `field_label` VARCHAR(100) NOT NULL,
    `is_required` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_fields_name` (`field_name`),
    KEY `idx_field_type` (`field_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `user_fields` (`field_name`, `field_type`, `field_label`, `is_required`)
SELECT 'city', 'text', 'Город', 0
WHERE NOT EXISTS (SELECT 1 FROM `user_fields` WHERE `field_name` = 'city');

INSERT INTO `user_fields` (`field_name`, `field_type`, `field_label`, `is_required`)
SELECT 'company', 'text', 'Компания', 0
WHERE NOT EXISTS (SELECT 1 FROM `user_fields` WHERE `field_name` = 'company');

INSERT INTO `user_fields` (`field_name`, `field_type`, `field_label`, `is_required`)
SELECT 'position', 'text', 'Должность', 0
WHERE NOT EXISTS (SELECT 1 FROM `user_fields` WHERE `field_name` = 'position');

INSERT INTO `user_fields` (`field_name`, `field_type`, `field_label`, `is_required`)
SELECT 'website', 'text', 'Веб-сайт', 0
WHERE NOT EXISTS (SELECT 1 FROM `user_fields` WHERE `field_name` = 'website');

INSERT INTO `user_fields` (`field_name`, `field_type`, `field_label`, `is_required`)
SELECT 'bio', 'textarea', 'О себе', 0
WHERE NOT EXISTS (SELECT 1 FROM `user_fields` WHERE `field_name` = 'bio');
