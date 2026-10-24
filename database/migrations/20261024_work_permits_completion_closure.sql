-- Завершение работ и неизменяемая запись закрытия наряда или распоряжения.
CREATE TABLE IF NOT EXISTS `work_permit_completions` (
 `document_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 `completed_at` DATETIME NOT NULL,
 `producer_employee_id` BIGINT UNSIGNED NOT NULL,
 `producer_name` VARCHAR(180) NOT NULL,
 `workplace_safe` TINYINT(1) NOT NULL,
 `crew_withdrawn` TINYINT(1) NOT NULL,
 `completion_note` VARCHAR(1000) NOT NULL,
 `recorded_by_user_id` INT DEFAULT NULL,
 `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT `fk_wp_completion_document` FOREIGN KEY (`document_id`) REFERENCES `work_permit_documents`(`id`) ON DELETE RESTRICT,
 CONSTRAINT `fk_wp_completion_actor` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_closures` (
 `document_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 `closed_at` DATETIME NOT NULL,
 `closer_employee_id` BIGINT UNSIGNED NOT NULL,
 `closer_name` VARCHAR(180) NOT NULL,
 `workplace_accepted` TINYINT(1) NOT NULL,
 `documents_handed_over` TINYINT(1) NOT NULL,
 `closure_note` VARCHAR(1000) NOT NULL,
 `recorded_by_user_id` INT DEFAULT NULL,
 `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT `fk_wp_closure_document` FOREIGN KEY (`document_id`) REFERENCES `work_permit_documents`(`id`) ON DELETE RESTRICT,
 CONSTRAINT `fk_wp_closure_actor` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `work_permit_lifecycle_commands`
 MODIFY COLUMN `action` ENUM('issue','first_admission','daily_admission','resume','break','day_end','crew_change','extension','complete','close_permit','close_order') NOT NULL;
