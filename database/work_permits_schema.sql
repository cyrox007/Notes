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
    `organization_id` BIGINT UNSIGNED DEFAULT NULL,
    `department_id` BIGINT UNSIGNED DEFAULT NULL,
    `facility_id` BIGINT UNSIGNED DEFAULT NULL,
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

-- Самостоятельные справочники, удаление запрещено: используем архивирование.
CREATE TABLE IF NOT EXISTS `work_permit_organizations` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(160) NOT NULL,
    `time_zone` VARCHAR(64) NOT NULL DEFAULT 'UTC',
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY `uq_wpo_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_departments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `organization_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY `uq_wpd_org_name` (`organization_id`,`name`),
    CONSTRAINT `fk_wpd_org` FOREIGN KEY (`organization_id`)
        REFERENCES `work_permit_organizations`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_facilities` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `organization_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(180) NOT NULL,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY `uq_wpf_org_name` (`organization_id`,`name`),
    CONSTRAINT `fk_wpf_org` FOREIGN KEY (`organization_id`)
        REFERENCES `work_permit_organizations`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_employees` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `organization_id` BIGINT UNSIGNED NOT NULL,
    `department_id` BIGINT UNSIGNED DEFAULT NULL,
    `name` VARCHAR(180) NOT NULL,
    `electrical_group` VARCHAR(12) NOT NULL DEFAULT '',
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY `uq_wpe_org_name` (`organization_id`,`name`),
    KEY `idx_wpe_department` (`department_id`,`id`),
    CONSTRAINT `fk_wpe_org` FOREIGN KEY (`organization_id`)
        REFERENCES `work_permit_organizations`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_wpe_department` FOREIGN KEY (`department_id`)
        REFERENCES `work_permit_departments`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_reference_history` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reference_kind` VARCHAR(24) NOT NULL,
    `reference_id` BIGINT UNSIGNED NOT NULL,
    `actor_user_id` INT DEFAULT NULL,
    `action` VARCHAR(20) NOT NULL,
    `version` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_wprh_reference` (`reference_kind`,`reference_id`,`id`),
    CONSTRAINT `fk_wprh_actor` FOREIGN KEY (`actor_user_id`)
        REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Дополнение 1.2: состав бригады и повторяемые команды изменения черновика.
CREATE TABLE IF NOT EXISTS `work_permit_crew` (
    `document_id` BIGINT UNSIGNED NOT NULL,
    `role` ENUM('issuer','responsible_manager','admitter','producer','observer','member') NOT NULL,
    `employee_id` BIGINT UNSIGNED NOT NULL,
    `employee_name` VARCHAR(180) NOT NULL,
    `electrical_group` VARCHAR(12) NOT NULL DEFAULT '',
    PRIMARY KEY (`document_id`,`role`,`employee_id`),
    KEY `idx_wp_crew_employee` (`employee_id`,`document_id`),
    CONSTRAINT `fk_wp_crew_document` FOREIGN KEY (`document_id`)
        REFERENCES `work_permit_documents`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_wp_crew_employee` FOREIGN KEY (`employee_id`)
        REFERENCES `work_permit_employees`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_edit_commands` (
    `command_id` CHAR(32) NOT NULL PRIMARY KEY,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `actor_user_id` INT DEFAULT NULL,
    `request_hash` CHAR(64) NOT NULL,
    `result_version` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_wp_edit_document` (`document_id`,`created_at`),
    CONSTRAINT `fk_wp_edit_document` FOREIGN KEY (`document_id`)
        REFERENCES `work_permit_documents`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_wp_edit_actor` FOREIGN KEY (`actor_user_id`)
        REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Шаблоны заданий и бригад, принадлежащие организациям модуля.
CREATE TABLE IF NOT EXISTS `work_permit_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `organization_id` BIGINT UNSIGNED NOT NULL,
  `kind` ENUM('job','crew','conditions','instructions') NOT NULL,
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

-- Регистрация выдачи и первого допуска к нарядам 1.2.
-- Эти события не являются цифровой подписью работников.
CREATE TABLE IF NOT EXISTS work_permit_issuances (
 document_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 issuer_employee_id BIGINT UNSIGNED NOT NULL,
 issuer_name VARCHAR(180) NOT NULL,
 producer_employee_id BIGINT UNSIGNED NOT NULL,
 producer_name VARCHAR(180) NOT NULL,
 planned_start DATETIME NOT NULL,
 planned_finish DATETIME NOT NULL,
 time_zone VARCHAR(64) NOT NULL DEFAULT 'UTC',
 special_instructions TEXT NOT NULL,
 work_conditions TEXT NULL,
 issued_by_user_id INT DEFAULT NULL,
 issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_wpi_doc FOREIGN KEY (document_id) REFERENCES work_permit_documents(id) ON DELETE RESTRICT,
 CONSTRAINT fk_wpi_actor FOREIGN KEY (issued_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_permit_safety_measures (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 document_id BIGINT UNSIGNED NOT NULL,
 sequence_no SMALLINT UNSIGNED NOT NULL,
 equipment VARCHAR(240) NOT NULL,
 isolation VARCHAR(1000) NOT NULL,
 remaining_live VARCHAR(500) NOT NULL,
 UNIQUE KEY uq_wp_safety_order (document_id,sequence_no),
 CONSTRAINT fk_wp_safety_doc FOREIGN KEY (document_id) REFERENCES work_permit_documents(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_permit_first_admissions (
 document_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 admitted_employee_id BIGINT UNSIGNED NOT NULL,
 admitted_name VARCHAR(180) NOT NULL,
 briefed_by_employee_id BIGINT UNSIGNED NOT NULL,
 briefed_by_name VARCHAR(180) NOT NULL,
 briefing_recipient_id BIGINT UNSIGNED NOT NULL,
 briefing_recipient_name VARCHAR(180) NOT NULL,
 started_at DATETIME NOT NULL,
 workplace_prepared TINYINT(1) NOT NULL,
 briefing_confirmed TINYINT(1) NOT NULL,
 recorded_by_user_id INT DEFAULT NULL,
 recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_wp_admit_doc FOREIGN KEY (document_id) REFERENCES work_permit_documents(id) ON DELETE RESTRICT,
 CONSTRAINT fk_wp_admit_actor FOREIGN KEY (recorded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_permit_lifecycle_commands (
 command_id CHAR(32) NOT NULL PRIMARY KEY,
 document_id BIGINT UNSIGNED NOT NULL,
 actor_user_id INT DEFAULT NULL,
 action ENUM('issue','first_admission','daily_admission','resume','break','day_end','crew_change','extension','complete','close_permit','close_order','preparation_permission','target_briefing') NOT NULL,
 request_hash CHAR(64) NOT NULL,
 result_version BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_wp_lifecycle_doc (document_id,created_at),
 CONSTRAINT fk_wp_command_doc FOREIGN KEY (document_id) REFERENCES work_permit_documents(id) ON DELETE RESTRICT,
 CONSTRAINT fk_wp_command_user FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Изменения состава бригады и продления ранее выданного наряда.
-- Исторические составы и прежние сроки хранятся отдельно от текущей карточки.
CREATE TABLE IF NOT EXISTS `work_permit_crew_revisions` (
 `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 `document_id` BIGINT UNSIGNED NOT NULL,
 `previous_version` BIGINT UNSIGNED NOT NULL,
 `changed_at` DATETIME NOT NULL,
 `reason` VARCHAR(1000) NOT NULL,
 `actor_user_id` INT DEFAULT NULL,
 `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY `uq_wp_crew_revision_version` (`document_id`,`previous_version`),
 KEY `idx_wp_crew_revision_doc` (`document_id`,`id`),
 CONSTRAINT `fk_wp_crew_revision_doc` FOREIGN KEY (`document_id`) REFERENCES `work_permit_documents`(`id`) ON DELETE RESTRICT,
 CONSTRAINT `fk_wp_crew_revision_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_crew_revision_members` (
 `revision_id` BIGINT UNSIGNED NOT NULL,
 `role` ENUM('issuer','responsible_manager','admitter','producer','observer','member') NOT NULL,
 `employee_id` BIGINT UNSIGNED NOT NULL,
 `employee_name` VARCHAR(180) NOT NULL,
 `electrical_group` VARCHAR(12) NOT NULL DEFAULT '',
 PRIMARY KEY (`revision_id`,`role`,`employee_id`),
 CONSTRAINT `fk_wp_crew_revision_members_revision` FOREIGN KEY (`revision_id`)
   REFERENCES `work_permit_crew_revisions`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_permit_extensions` (
 `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 `document_id` BIGINT UNSIGNED NOT NULL,
 `previous_version` BIGINT UNSIGNED NOT NULL,
 `previous_finish` DATETIME NOT NULL,
 `new_finish` DATETIME NOT NULL,
 `extended_at` DATETIME NOT NULL,
 `reason` VARCHAR(1000) NOT NULL,
 `issuer_employee_id` BIGINT UNSIGNED NOT NULL,
 `issuer_name` VARCHAR(180) NOT NULL,
 `actor_user_id` INT DEFAULT NULL,
 `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY `uq_wp_extension_version` (`document_id`,`previous_version`),
 KEY `idx_wp_extension_doc` (`document_id`,`id`),
 CONSTRAINT `fk_wp_extension_doc` FOREIGN KEY (`document_id`) REFERENCES `work_permit_documents`(`id`) ON DELETE RESTRICT,
 CONSTRAINT `fk_wp_extension_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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


-- Самостоятельный учёт разрешений на подготовку места и допуск.
-- Подтверждения в Notes не считаются личными подписями работников.
CREATE TABLE IF NOT EXISTS \`work_permit_preparation_permissions\` (
 \`id\` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 \`document_id\` BIGINT UNSIGNED NOT NULL,
 \`granted_at\` DATETIME NOT NULL,
 \`grantor_employee_id\` BIGINT UNSIGNED NOT NULL,
 \`grantor_name\` VARCHAR(180) NOT NULL,
 \`receiver_employee_id\` BIGINT UNSIGNED NOT NULL,
 \`receiver_name\` VARCHAR(180) NOT NULL,
 \`workplace\` VARCHAR(500) NOT NULL,
 \`communication_method\` ENUM('personally','telephone','radio','other') NOT NULL,
 \`note\` VARCHAR(1000) NOT NULL DEFAULT '',
 \`recorded_by_user_id\` INT DEFAULT NULL,
 \`recorded_at\` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY \`idx_wp_preparation_permissions_doc\` (\`document_id\`,\`id\`),
 CONSTRAINT \`fk_wp_preparation_permission_doc\` FOREIGN KEY (\`document_id\`) REFERENCES \`work_permit_documents\`(\`id\`) ON DELETE RESTRICT,
 CONSTRAINT \`fk_wp_preparation_permission_actor\` FOREIGN KEY (\`recorded_by_user_id\`) REFERENCES \`users\`(\`id\`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Отдельный инструктаж каждому получателю, с указанием этапа и проводящего.
CREATE TABLE IF NOT EXISTS \`work_permit_target_briefings\` (
 \`id\` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 \`document_id\` BIGINT UNSIGNED NOT NULL,
 \`stage\` ENUM('issuer','admitter','manager','producer') NOT NULL,
 \`briefed_at\` DATETIME NOT NULL,
 \`instructor_employee_id\` BIGINT UNSIGNED NOT NULL,
 \`instructor_name\` VARCHAR(180) NOT NULL,
 \`recipient_employee_id\` BIGINT UNSIGNED NOT NULL,
 \`recipient_name\` VARCHAR(180) NOT NULL,
 \`recipient_group\` VARCHAR(12) NOT NULL DEFAULT '',
 \`topic\` VARCHAR(1000) NOT NULL,
 \`recorded_by_user_id\` INT DEFAULT NULL,
 \`recorded_at\` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY \`idx_wp_target_briefings_doc\` (\`document_id\`,\`id\`),
 CONSTRAINT \`fk_wp_target_briefing_doc\` FOREIGN KEY (\`document_id\`) REFERENCES \`work_permit_documents\`(\`id\`) ON DELETE RESTRICT,
 CONSTRAINT \`fk_wp_target_briefing_actor\` FOREIGN KEY (\`recorded_by_user_id\`) REFERENCES \`users\`(\`id\`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
