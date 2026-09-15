-- Workspace Organizer 0.14 persisted module lifecycle registry.
-- Rows are reconciled from validated on-disk manifests by ModuleLifecycleStore.

CREATE TABLE IF NOT EXISTS `module_lifecycle` (
    `module_id` VARCHAR(64) NOT NULL,
    `installed_version` VARCHAR(64) NOT NULL,
    `manifest_hash` CHAR(64) NOT NULL,
    `configured_state` ENUM(
        'discovered','installed','enabled','disabled','degraded','quarantined','uninstalled'
    ) NOT NULL,
    `effective_state` ENUM(
        'discovered','installed','enabled','disabled','incompatible','degraded','quarantined','uninstalled'
    ) NOT NULL,
    `last_error` VARCHAR(1000) DEFAULT NULL,
    `discovered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `state_changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`module_id`),
    KEY `idx_module_lifecycle_effective_state` (`effective_state`),
    KEY `idx_module_lifecycle_configured_state` (`configured_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
