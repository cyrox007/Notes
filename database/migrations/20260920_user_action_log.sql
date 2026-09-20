-- ============================================================
-- Workspace Organizer core user-action audit schema
-- Durable metadata-only journal for authenticated mutations.
-- ============================================================

CREATE TABLE IF NOT EXISTS `user_action_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `occurred_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `actor_id` INT DEFAULT NULL,
    `actor_uid` VARCHAR(64) NOT NULL,
    `actor_username` VARCHAR(50) NOT NULL,
    `module_id` VARCHAR(64) NOT NULL,
    `action` VARCHAR(128) NOT NULL,
    `transport` ENUM('http','websocket','system','cli') NOT NULL DEFAULT 'http',
    `outcome` ENUM('success','failure') NOT NULL DEFAULT 'success',
    `status_code` SMALLINT UNSIGNED DEFAULT NULL,
    `details` JSON DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_action_log_time` (`occurred_at`,`id`),
    KEY `idx_user_action_log_actor` (`actor_id`,`occurred_at`,`id`),
    KEY `idx_user_action_log_module_action` (`module_id`,`action`,`occurred_at`,`id`),
    KEY `idx_user_action_log_outcome` (`outcome`,`occurred_at`,`id`),
    CONSTRAINT `fk_user_action_log_actor`
        FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions` (`code`, `module_id`, `description`) VALUES
('admin.audit.view', 'admin', 'Просмотр журнала действий пользователей')
ON DUPLICATE KEY UPDATE
    `module_id` = VALUES(`module_id`),
    `description` = VALUES(`description`);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code = 'admin.audit.view'
WHERE r.code IN ('admin','superadmin');
