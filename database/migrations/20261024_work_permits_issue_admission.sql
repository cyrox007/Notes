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
 special_instructions TEXT NOT NULL,
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
 action ENUM('issue','first_admission') NOT NULL,
 request_hash CHAR(64) NOT NULL,
 result_version BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_wp_lifecycle_doc (document_id,created_at),
 CONSTRAINT fk_wp_command_doc FOREIGN KEY (document_id) REFERENCES work_permit_documents(id) ON DELETE RESTRICT,
 CONSTRAINT fk_wp_command_user FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
