-- Изменения состава бригады и продления ранее выданного наряда.
-- Исторические составы и прежние сроки хранятся отдельно от текущей карточки.
CREATE TABLE IF NOT EXISTS `work_permit_crew_revisions` (
 `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 `document_id` BIGINT UNSIGNED NOT NULL,
 `previous_version` BIGINT UNSIGNED NOT NULL,
 `changed_at` DATETIME NOT NULL,
 `reason` VARCHAR(1000) NOT NULL,
 `actor_user_id` INT DEFAULT NULL,
 `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY `uq_wp_crew_revision_version` (`document_id`,`previous_version`),
 KEY `idx_wp_crew_revision_doc` (`document_id`,`id`),
 CONSTRAINT `fk_wp_crew_revision_doc` FOREIGN KEY (`document_id`) REFERENCES `work_permit_documents`(`id`) ON DELETE RESTRICT,
 CONSTRAINT `fk_wp_crew_revision_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_crew_revision_members` (
 `revision_id` BIGINT UNSIGNED NOT NULL,
 `role` ENUM('issuer','responsible_manager','admitter','producer','observer','member') NOT NULL,
 `employee_id` BIGINT UNSIGNED NOT NULL,
 `employee_name` VARCHAR(180) NOT NULL,
 `electrical_group` VARCHAR(12) NOT NULL DEFAULT '',
 PRIMARY KEY (`revision_id`,`role`,`employee_id`),
 CONSTRAINT `fk_wp_crew_revision_members_revision` FOREIGN KEY (`revision_id`)
   REFERENCES `work_permit_crew_revisions`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_extensions` (
 `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 `document_id` BIGINT UNSIGNED NOT NULL,
 `previous_version` BIGINT UNSIGNED NOT NULL,
 `previous_finish` DATETIME NOT NULL,
 `new_finish` DATETIME NOT NULL,
 `extended_at` DATETIME NOT NULL,
 `reason` VARCHAR(1000) NOT NULL,
 `issuer_employee_id` BIGINT UNSIGNED NOT NULL,
 `issuer_name` VARCHAR(180) NOT NULL,
 `actor_user_id` INT DEFAULT NULL,
 `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY `uq_wp_extension_version` (`document_id`,`previous_version`),
 KEY `idx_wp_extension_doc` (`document_id`,`id`),
 CONSTRAINT `fk_wp_extension_doc` FOREIGN KEY (`document_id`) REFERENCES `work_permit_documents`(`id`) ON DELETE RESTRICT,
 CONSTRAINT `fk_wp_extension_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `work_permit_lifecycle_commands`
 MODIFY COLUMN `action` ENUM('issue','first_admission','daily_admission','resume','break','day_end','crew_change','extension') NOT NULL;
