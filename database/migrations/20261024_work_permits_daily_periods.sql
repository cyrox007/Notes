-- Периоды работы бригады, перерывы и повторные допуски по дням.
-- Ранее оформленный первичный допуск становится первой строкой истории смен.
CREATE TABLE IF NOT EXISTS `work_permit_work_periods` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `start_kind` ENUM('first','daily_admission','resume') NOT NULL,
  `started_at` DATETIME NOT NULL,
  `admitter_employee_id` BIGINT UNSIGNED NOT NULL,
  `admitter_name` VARCHAR(180) NOT NULL,
  `instructor_employee_id` BIGINT UNSIGNED NOT NULL,
  `instructor_name` VARCHAR(180) NOT NULL,
  `recipient_employee_id` BIGINT UNSIGNED NOT NULL,
  `recipient_name` VARCHAR(180) NOT NULL,
  `workplace_prepared` TINYINT(1) NOT NULL DEFAULT 1,
  `briefing_confirmed` TINYINT(1) NOT NULL DEFAULT 1,
  `started_by_user_id` INT DEFAULT NULL,
  `ended_at` DATETIME DEFAULT NULL,
  `end_kind` ENUM('break','day_end') DEFAULT NULL,
  `end_note` VARCHAR(1000) DEFAULT NULL,
  `ended_by_user_id` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_wp_period_start` (`document_id`,`started_at`),
  KEY `idx_wp_period_doc` (`document_id`,`id`),
  CONSTRAINT `fk_wp_period_doc` FOREIGN KEY (`document_id`)
    REFERENCES `work_permit_documents`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_wp_period_start_user` FOREIGN KEY (`started_by_user_id`)
    REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wp_period_end_user` FOREIGN KEY (`ended_by_user_id`)
    REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `work_permit_lifecycle_commands`
 MODIFY COLUMN `action` ENUM('issue','first_admission','daily_admission','resume','break','day_end') NOT NULL;

-- Без потери старых данных: включить уже существовавший первый допуск.
INSERT INTO `work_permit_work_periods` (
  `document_id`,`start_kind`,`started_at`,`admitter_employee_id`,
  `admitter_name`,`instructor_employee_id`,`instructor_name`,
  `recipient_employee_id`,`recipient_name`,`workplace_prepared`,
  `briefing_confirmed`,`started_by_user_id`
)
SELECT a.document_id,'first',a.started_at,a.admitted_employee_id,a.admitted_name,
 a.briefed_by_employee_id,a.briefed_by_name,a.briefing_recipient_id,
 a.briefing_recipient_name,a.workplace_prepared,a.briefing_confirmed,a.recorded_by_user_id
FROM `work_permit_first_admissions` a
WHERE NOT EXISTS (
 SELECT 1 FROM `work_permit_work_periods` p WHERE p.document_id=a.document_id
);
