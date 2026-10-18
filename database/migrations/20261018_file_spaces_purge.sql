-- Журнал безвозвратной очистки Files 1.2.
-- Запись preparing переживает отказ процесса и позволяет вернуть файлы из карантина.
-- После commit данные уже удалены из БД, а временные байты можно удалить повторно.
CREATE TABLE IF NOT EXISTS file_space_purge_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid CHAR(32) NOT NULL,
    space_id BIGINT UNSIGNED NOT NULL,
    root_node_id BIGINT UNSIGNED NOT NULL,
    root_uid CHAR(32) NOT NULL,
    status ENUM('preparing','committed','finished','rolled_back') NOT NULL DEFAULT 'preparing',
    node_count INT UNSIGNED NOT NULL DEFAULT 0,
    file_count INT UNSIGNED NOT NULL DEFAULT 0,
    bytes_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_file_space_purge_uid (uid),
    KEY idx_file_space_purge_pending (space_id,status,created_at),
    CONSTRAINT fk_file_space_purge_space FOREIGN KEY (space_id) REFERENCES file_spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
