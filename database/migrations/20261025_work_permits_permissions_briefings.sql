
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

ALTER TABLE `work_permit_lifecycle_commands`
 MODIFY COLUMN `action` ENUM('issue','first_admission','daily_admission','resume','break','day_end','crew_change','extension','complete','close_permit','close_order','preparation_permission','target_briefing') NOT NULL;
