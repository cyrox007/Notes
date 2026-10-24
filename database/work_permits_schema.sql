-- Черновики журнала нарядов и распоряжений Notes 1.2.
-- Полный жизненный цикл, справочники и PDF добавляются отдельными этапами.
CREATE TABLE IF NOT EXISTS `work_permit_documents` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `kind` ENUM('permit','order') NOT NULL,
    `state` ENUM('draft','issued','closed') NOT NULL DEFAULT 'draft',
    `organization` VARCHAR(160) NOT NULL,
    `subdivision` VARCHAR(160) NOT NULL DEFAULT '',
    `facility` VARCHAR(180) NOT NULL,
    `work_description` TEXT NOT NULL,
    `organization_id` BIGINT UNSIGNED DEFAULT NULL,
    `department_id` BIGINT UNSIGNED DEFAULT NULL,
    `facility_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_by_user_id` INT DEFAULT NULL,
    `creation_command_id` VARCHAR(96) NOT NULL,
    `creation_request_hash` CHAR(64) NOT NULL,
    `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_work_permit_command` (`creation_command_id`),
    KEY `idx_work_permit_owner_date` (`created_by_user_id`,`created_at`,`id`),
    KEY `idx_work_permit_kind_state` (`kind`,`state`,`id`),
    CONSTRAINT `fk_work_permit_creator` FOREIGN KEY (`created_by_user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `actor_user_id` INT DEFAULT NULL,
    `event_type` VARCHAR(32) NOT NULL,
    `version` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_work_permit_history_document` (`document_id`,`id`),
    CONSTRAINT `fk_work_permit_history_document` FOREIGN KEY (`document_id`)
        REFERENCES `work_permit_documents` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_work_permit_history_actor` FOREIGN KEY (`actor_user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Новый модуль подключается отдельно и не получает административных
-- полномочий по умолчанию: назначение ролей остаётся под управлением ядра.
INSERT INTO `permissions` (`code`,`module_id`,`description`) VALUES
('work_permits.use','work-permits','Просмотр собственных черновиков и регистрация'),
('work_permits.manage','work-permits','Управление журналом нарядов и допусков')
ON DUPLICATE KEY UPDATE `module_id`=VALUES(`module_id`),`description`=VALUES(`description`);

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.id,p.id FROM `roles` r JOIN `permissions` p ON p.code='work_permits.use'
WHERE r.code IN ('user','admin','superadmin');

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.id,p.id FROM `roles` r JOIN `permissions` p ON p.code='work_permits.manage'
WHERE r.code IN ('admin','superadmin');

-- Самостоятельные справочники, удаление запрещено: используем архивирование.
CREATE TABLE IF NOT EXISTS `work_permit_organizations` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(160) NOT NULL,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY `uq_wpo_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_departments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `organization_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY `uq_wpd_org_name` (`organization_id`,`name`),
    CONSTRAINT `fk_wpd_org` FOREIGN KEY (`organization_id`)
        REFERENCES `work_permit_organizations`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_facilities` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `organization_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(180) NOT NULL,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY `uq_wpf_org_name` (`organization_id`,`name`),
    CONSTRAINT `fk_wpf_org` FOREIGN KEY (`organization_id`)
        REFERENCES `work_permit_organizations`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_employees` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `organization_id` BIGINT UNSIGNED NOT NULL,
    `department_id` BIGINT UNSIGNED DEFAULT NULL,
    `name` VARCHAR(180) NOT NULL,
    `electrical_group` VARCHAR(12) NOT NULL DEFAULT '',
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY `uq_wpe_org_name` (`organization_id`,`name`),
    KEY `idx_wpe_department` (`department_id`,`id`),
    CONSTRAINT `fk_wpe_org` FOREIGN KEY (`organization_id`)
        REFERENCES `work_permit_organizations`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_wpe_department` FOREIGN KEY (`department_id`)
        REFERENCES `work_permit_departments`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_reference_history` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reference_kind` VARCHAR(24) NOT NULL,
    `reference_id` BIGINT UNSIGNED NOT NULL,
    `actor_user_id` INT DEFAULT NULL,
    `action` VARCHAR(20) NOT NULL,
    `version` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_wprh_reference` (`reference_kind`,`reference_id`,`id`),
    CONSTRAINT `fk_wprh_actor` FOREIGN KEY (`actor_user_id`)
        REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Дополнение 1.2: состав бригады и повторяемые команды изменения черновика.
CREATE TABLE IF NOT EXISTS `work_permit_crew` (
    `document_id` BIGINT UNSIGNED NOT NULL,
    `role` ENUM('issuer','responsible_manager','admitter','producer','observer','member') NOT NULL,
    `employee_id` BIGINT UNSIGNED NOT NULL,
    `employee_name` VARCHAR(180) NOT NULL,
    `electrical_group` VARCHAR(12) NOT NULL DEFAULT '',
    PRIMARY KEY (`document_id`,`role`,`employee_id`),
    KEY `idx_wp_crew_employee` (`employee_id`,`document_id`),
    CONSTRAINT `fk_wp_crew_document` FOREIGN KEY (`document_id`)
        REFERENCES `work_permit_documents`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_wp_crew_employee` FOREIGN KEY (`employee_id`)
        REFERENCES `work_permit_employees`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_edit_commands` (
    `command_id` CHAR(32) NOT NULL PRIMARY KEY,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `actor_user_id` INT DEFAULT NULL,
    `request_hash` CHAR(64) NOT NULL,
    `result_version` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_wp_edit_document` (`document_id`,`created_at`),
    CONSTRAINT `fk_wp_edit_document` FOREIGN KEY (`document_id`)
        REFERENCES `work_permit_documents`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_wp_edit_actor` FOREIGN KEY (`actor_user_id`)
        REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
