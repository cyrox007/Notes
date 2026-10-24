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
