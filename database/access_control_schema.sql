-- ============================================================
-- Workspace Organizer 0.14 - canonical RBAC/access-control schema
-- Requires the canonical users table to exist first.
-- ============================================================

CREATE TABLE IF NOT EXISTS `roles` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(64) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_roles_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `permissions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(128) NOT NULL,
    `module_id` VARCHAR(64) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_permissions_code` (`code`),
    KEY `idx_permissions_module` (`module_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id` BIGINT UNSIGNED NOT NULL,
    `permission_id` BIGINT UNSIGNED NOT NULL,
    `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`role_id`, `permission_id`),
    CONSTRAINT `fk_role_permissions_role`
        FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_role_permissions_permission`
        FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_roles` (
    `user_id` INT NOT NULL,
    `role_id` BIGINT UNSIGNED NOT NULL,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `assigned_by` INT DEFAULT NULL,
    PRIMARY KEY (`user_id`, `role_id`),
    KEY `idx_user_roles_role` (`role_id`, `user_id`),
    KEY `idx_user_roles_assigned_by` (`assigned_by`),
    CONSTRAINT `fk_user_roles_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_user_roles_role`
        FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_user_roles_assigned_by`
        FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RBAC answers whether an action is allowed. Module policies answer how much,
-- how often, or which typed resources a role may use. Keeping these contracts
-- separate prevents quota/rate/type limits from becoming pseudo-permissions.
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

INSERT INTO `roles` (`code`, `name`, `description`, `is_system`) VALUES
('superadmin', 'Суперадминистратор', 'Системный владелец установки', 1),
('admin', 'Администратор', 'Администрирование пользователей и настроек', 1),
('user', 'Пользователь', 'Базовая роль пользователя Workspace Organizer', 1)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `is_system` = VALUES(`is_system`);

INSERT INTO `permissions` (`code`, `module_id`, `description`) VALUES
('admin.access', 'admin', 'Доступ к административной панели'),
('admin.users.manage', 'admin', 'Управление состоянием пользователей'),
('admin.settings.manage', 'admin', 'Изменение системных настроек'),
('admin.roles.manage', 'admin', 'Управление ролями и назначениями прав'),
('notes.use', 'notes', 'Использование модуля Notes в пределах resource ACL'),
('tasks.use', 'tasks', 'Использование модуля Tasks в пределах resource ACL'),
('files.use', 'files', 'Использование File Manager в пределах resource ACL'),
('messenger.use', 'messenger', 'Использование Messenger в пределах dialog/message ACL'),
('profile.use', 'profile', 'Использование собственного профиля и разрешённых public-profile операций')
ON DUPLICATE KEY UPDATE
    `module_id` = VALUES(`module_id`),
    `description` = VALUES(`description`);

-- Default user capabilities.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN ('notes.use','tasks.use','files.use','messenger.use','profile.use')
WHERE r.code IN ('user','admin','superadmin');

-- Admin capabilities. Role-management remains superadmin-only during the 0.14 migration.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN ('admin.access','admin.users.manage','admin.settings.manage')
WHERE r.code IN ('admin','superadmin');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code = 'admin.roles.manage'
WHERE r.code = 'superadmin';

-- Fresh installs normally have no users yet when this schema is imported. The
-- INSERT trigger keeps the legacy user-creation paths compatible until every
-- caller writes user_roles explicitly.
DROP TRIGGER IF EXISTS `users_after_insert_rbac_bridge`;
CREATE TRIGGER `users_after_insert_rbac_bridge`
AFTER INSERT ON `users`
FOR EACH ROW
INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`)
SELECT NEW.id, r.id
FROM roles r
WHERE r.code = CASE
    WHEN NEW.role = 1 THEN 'superadmin'
    WHEN NEW.role = 111 THEN 'admin'
    ELSE 'user'
END;
