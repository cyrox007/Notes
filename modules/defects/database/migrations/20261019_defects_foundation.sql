-- Каноническая схема автономного модуля «Дефекты» линии 1.2.
-- Данные и ограничения принадлежат только модулю.

CREATE TABLE IF NOT EXISTS `defect_areas` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(160) NOT NULL,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_defect_area_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `defect_object_categories` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `area_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_defect_category_area_name` (`area_id`, `name`),
    CONSTRAINT `fk_defect_category_area` FOREIGN KEY (`area_id`)
        REFERENCES `defect_areas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `defect_objects` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(180) NOT NULL,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_defect_object_category_name` (`category_id`, `name`),
    CONSTRAINT `fk_defect_object_category` FOREIGN KEY (`category_id`)
        REFERENCES `defect_object_categories` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `defect_services` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(160) NOT NULL,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_defect_service_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `defects` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `object_id` BIGINT UNSIGNED NOT NULL,
    `description` TEXT NOT NULL,
    `priority` ENUM('urgent', 'first', 'important', 'normal') NOT NULL,
    `discovered_at` DATETIME NOT NULL,
    `due_at` DATETIME NOT NULL,
    `registered_by_name` VARCHAR(160) NOT NULL,
    `registered_by_user_id` INT DEFAULT NULL,
    `creation_command_id` VARCHAR(96) NOT NULL,
    `creation_request_hash` CHAR(64) NOT NULL,
    `status` ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    `completed_work` TEXT DEFAULT NULL,
    `closed_at` DATETIME DEFAULT NULL,
    `closed_by_user_id` INT DEFAULT NULL,
    `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_defects_command` (`creation_command_id`),
    KEY `idx_defects_state_due` (`status`, `due_at`, `id`),
    KEY `idx_defects_object_date` (`object_id`, `discovered_at`, `id`),
    KEY `idx_defects_priority_status` (`priority`, `status`, `id`),
    CONSTRAINT `fk_defects_object` FOREIGN KEY (`object_id`)
        REFERENCES `defect_objects` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_defects_registered_user` FOREIGN KEY (`registered_by_user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_defects_closed_user` FOREIGN KEY (`closed_by_user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `defect_responsible_services` (
    `defect_id` BIGINT UNSIGNED NOT NULL,
    `service_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`defect_id`, `service_id`),
    KEY `idx_defect_responsible_service` (`service_id`, `defect_id`),
    CONSTRAINT `fk_defect_responsible_record` FOREIGN KEY (`defect_id`)
        REFERENCES `defects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_defect_responsible_service` FOREIGN KEY (`service_id`)
        REFERENCES `defect_services` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Разрешения выполняются через общий RBAC ядра.
INSERT INTO `permissions` (`code`, `module_id`, `description`) VALUES
('defects.use','defects','Просмотр и регистрация дефектов'),
('defects.manage','defects','Управление справочниками и расширенными действиями')
ON DUPLICATE KEY UPDATE `module_id`=VALUES(`module_id`),`description`=VALUES(`description`);

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='defects.use'
WHERE r.code IN ('user','admin','superadmin');

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='defects.manage'
WHERE r.code IN ('admin','superadmin');
