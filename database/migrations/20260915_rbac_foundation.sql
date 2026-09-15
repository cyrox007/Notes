-- Workspace Organizer 0.14 RBAC foundation.
-- Keeps legacy users.role operational while introducing independent account state
-- and persisted role/permission assignments.

ALTER TABLE `users`
    ADD COLUMN `account_status` ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active' AFTER `is_active`;

UPDATE `users`
SET `account_status` = CASE
    WHEN `role` = 999 THEN 'blocked'
    WHEN `is_active` = 0 OR `role` = 899 THEN 'inactive'
    ELSE 'active'
END;

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
('profile.use', 'profile', 'Использование профиля в пределах profile ACL')
ON DUPLICATE KEY UPDATE
    `module_id` = VALUES(`module_id`),
    `description` = VALUES(`description`);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN ('notes.use','tasks.use','files.use','messenger.use','profile.use')
WHERE r.code IN ('user','admin','superadmin');

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

-- Preserve authorization identity when a legacy status code (899/999) is used:
-- both are mapped to the base user role, while account_status stores the state.
INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`)
SELECT u.id, r.id
FROM users u
JOIN roles r ON r.code = CASE
    WHEN u.role = 1 THEN 'superadmin'
    WHEN u.role = 111 THEN 'admin'
    ELSE 'user'
END;

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

DROP TRIGGER IF EXISTS `users_before_update_account_status_bridge`;
CREATE TRIGGER `users_before_update_account_status_bridge`
BEFORE UPDATE ON `users`
FOR EACH ROW
SET NEW.account_status = CASE
    WHEN NEW.role = 999 THEN 'blocked'
    WHEN NEW.is_active = 0 OR NEW.role = 899 THEN 'inactive'
    ELSE 'active'
END;
