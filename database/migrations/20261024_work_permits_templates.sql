-- Шаблоны заданий и бригад, принадлежащие организациям модуля.
CREATE TABLE IF NOT EXISTS `work_permit_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `organization_id` BIGINT UNSIGNED NOT NULL,
  `kind` ENUM('job','crew') NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `work_description` TEXT DEFAULT NULL,
  `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
  `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_by_user_id` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_wp_template_name` (`organization_id`,`kind`,`name`),
  KEY `idx_wp_template_active` (`organization_id`,`kind`,`is_archived`,`id`),
  CONSTRAINT `fk_wp_template_org` FOREIGN KEY (`organization_id`)
    REFERENCES `work_permit_organizations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_wp_template_author` FOREIGN KEY (`created_by_user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_template_crew` (
  `template_id` BIGINT UNSIGNED NOT NULL,
  `role` ENUM('issuer','responsible_manager','admitter','producer','observer','member') NOT NULL,
  `employee_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`template_id`,`role`,`employee_id`),
  KEY `idx_wp_template_employee` (`employee_id`,`template_id`),
  CONSTRAINT `fk_wp_template_crew_template` FOREIGN KEY (`template_id`)
    REFERENCES `work_permit_templates` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_wp_template_crew_employee` FOREIGN KEY (`employee_id`)
    REFERENCES `work_permit_employees` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_template_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `template_id` BIGINT UNSIGNED NOT NULL,
  `actor_user_id` INT DEFAULT NULL,
  `action` VARCHAR(24) NOT NULL,
  `version` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_wp_template_history` (`template_id`,`id`),
  CONSTRAINT `fk_wp_template_history_template` FOREIGN KEY (`template_id`)
    REFERENCES `work_permit_templates` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_wp_template_history_actor` FOREIGN KEY (`actor_user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
