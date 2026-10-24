-- Расширение установленного 1.2: не изменяем предыдущую миграцию.
-- Новые документы сохраняют ссылки на справочники и текстовые снимки названий.

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


SET @has_wpd_organization_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='work_permit_documents' AND column_name='organization_id'
);
SET @add_wpd_organization_id := IF(@has_wpd_organization_id=0,
    'ALTER TABLE work_permit_documents ADD COLUMN organization_id BIGINT UNSIGNED DEFAULT NULL',
    'SELECT 1');
PREPARE stmt_wpd_organization_id FROM @add_wpd_organization_id;
EXECUTE stmt_wpd_organization_id;
DEALLOCATE PREPARE stmt_wpd_organization_id;


SET @has_wpd_department_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='work_permit_documents' AND column_name='department_id'
);
SET @add_wpd_department_id := IF(@has_wpd_department_id=0,
    'ALTER TABLE work_permit_documents ADD COLUMN department_id BIGINT UNSIGNED DEFAULT NULL',
    'SELECT 1');
PREPARE stmt_wpd_department_id FROM @add_wpd_department_id;
EXECUTE stmt_wpd_department_id;
DEALLOCATE PREPARE stmt_wpd_department_id;


SET @has_wpd_facility_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='work_permit_documents' AND column_name='facility_id'
);
SET @add_wpd_facility_id := IF(@has_wpd_facility_id=0,
    'ALTER TABLE work_permit_documents ADD COLUMN facility_id BIGINT UNSIGNED DEFAULT NULL',
    'SELECT 1');
PREPARE stmt_wpd_facility_id FROM @add_wpd_facility_id;
EXECUTE stmt_wpd_facility_id;
DEALLOCATE PREPARE stmt_wpd_facility_id;
