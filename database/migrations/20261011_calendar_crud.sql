-- Calendar 1.1: пользовательский CRUD событий, поля карточки и сохранённые напоминания.

SET @calendar_description_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'calendar_events' AND column_name = 'description'
);
SET @calendar_description_sql := IF(
    @calendar_description_exists = 0,
    'ALTER TABLE `calendar_events` ADD COLUMN `description` TEXT DEFAULT NULL AFTER `title`',
    'SELECT 1'
);
PREPARE calendar_description_stmt FROM @calendar_description_sql;
EXECUTE calendar_description_stmt;
DEALLOCATE PREPARE calendar_description_stmt;

SET @calendar_location_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'calendar_events' AND column_name = 'location'
);
SET @calendar_location_sql := IF(
    @calendar_location_exists = 0,
    'ALTER TABLE `calendar_events` ADD COLUMN `location` VARCHAR(255) DEFAULT NULL AFTER `description`',
    'SELECT 1'
);
PREPARE calendar_location_stmt FROM @calendar_location_sql;
EXECUTE calendar_location_stmt;
DEALLOCATE PREPARE calendar_location_stmt;

SET @calendar_all_day_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'calendar_events' AND column_name = 'is_all_day'
);
SET @calendar_all_day_sql := IF(
    @calendar_all_day_exists = 0,
    'ALTER TABLE `calendar_events` ADD COLUMN `is_all_day` TINYINT(1) NOT NULL DEFAULT 0 AFTER `location`',
    'SELECT 1'
);
PREPARE calendar_all_day_stmt FROM @calendar_all_day_sql;
EXECUTE calendar_all_day_stmt;
DEALLOCATE PREPARE calendar_all_day_stmt;

CREATE TABLE IF NOT EXISTS `calendar_event_reminder_offsets` (
    `event_id` BIGINT UNSIGNED NOT NULL,
    `offset_seconds` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`event_id`, `offset_seconds`),
    CONSTRAINT `fk_calendar_event_reminder_offsets_event`
        FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions` (`code`, `module_id`, `description`)
VALUES ('calendar.use', 'calendar', 'Использование личного Calendar и напоминаний')
ON DUPLICATE KEY UPDATE
    `module_id` = VALUES(`module_id`),
    `description` = VALUES(`description`);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code = 'calendar.use'
WHERE r.code IN ('user','admin','superadmin');
