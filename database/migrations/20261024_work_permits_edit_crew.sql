-- Дополнение 1.2: состав бригады и повторяемые команды изменения черновика.
CREATE TABLE IF NOT EXISTS \`work_permit_crew\` (
    \`document_id\` BIGINT UNSIGNED NOT NULL,
    \`role\` ENUM('issuer','responsible_manager','admitter','producer','observer','member') NOT NULL,
    \`employee_id\` BIGINT UNSIGNED NOT NULL,
    \`employee_name\` VARCHAR(180) NOT NULL,
    \`electrical_group\` VARCHAR(12) NOT NULL DEFAULT '',
    PRIMARY KEY (\`document_id\`,\`role\`,\`employee_id\`),
    KEY \`idx_wp_crew_employee\` (\`employee_id\`,\`document_id\`),
    CONSTRAINT \`fk_wp_crew_document\` FOREIGN KEY (\`document_id\`)
        REFERENCES \`work_permit_documents\`(\`id\`) ON DELETE RESTRICT,
    CONSTRAINT \`fk_wp_crew_employee\` FOREIGN KEY (\`employee_id\`)
        REFERENCES \`work_permit_employees\`(\`id\`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS \`work_permit_edit_commands\` (
    \`command_id\` CHAR(32) NOT NULL PRIMARY KEY,
    \`document_id\` BIGINT UNSIGNED NOT NULL,
    \`actor_user_id\` INT DEFAULT NULL,
    \`request_hash\` CHAR(64) NOT NULL,
    \`result_version\` BIGINT UNSIGNED NOT NULL,
    \`created_at\` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY \`idx_wp_edit_document\` (\`document_id\`,\`created_at\`),
    CONSTRAINT \`fk_wp_edit_document\` FOREIGN KEY (\`document_id\`)
        REFERENCES \`work_permit_documents\`(\`id\`) ON DELETE RESTRICT,
    CONSTRAINT \`fk_wp_edit_actor\` FOREIGN KEY (\`actor_user_id\`)
        REFERENCES \`users\`(\`id\`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
