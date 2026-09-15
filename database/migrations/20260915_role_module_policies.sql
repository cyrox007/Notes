-- ============================================================
-- Workspace Organizer 0.14 Beta 4 - role module policies
-- Typed/quantitative limits are intentionally separate from RBAC permissions.
-- ============================================================

CREATE TABLE IF NOT EXISTS `role_module_policies` (
    `role_id` BIGINT UNSIGNED NOT NULL,
    `module_id` VARCHAR(64) NOT NULL,
    `policy_key` VARCHAR(96) NOT NULL,
    `value_type` ENUM('bool', 'int', 'string_list') NOT NULL,
    `value_json` JSON NOT NULL,
    `updated_by` INT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`role_id`, `module_id`, `policy_key`),
    KEY `idx_role_module_policies_module` (`module_id`, `policy_key`, `role_id`),
    KEY `idx_role_module_policies_updated_by` (`updated_by`),
    CONSTRAINT `fk_role_module_policies_role`
        FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_role_module_policies_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
