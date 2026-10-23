-- Ручное подтверждение получения у контролируемого адресата.
-- Это показание оператора, а не автоматическая телеметрия внешнего сервиса.
CREATE TABLE IF NOT EXISTS notification_receipt_attestations (
    job_id BIGINT UNSIGNED NOT NULL,
    operator_user_id INT NOT NULL,
    receipt_state VARCHAR(16) NOT NULL,
    confirmed_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (job_id),
    CONSTRAINT fk_notification_receipt_job FOREIGN KEY (job_id)
        REFERENCES notification_delivery_jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_receipt_operator FOREIGN KEY (operator_user_id)
        REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
