-- Безопасный архив дефекта: карточка, авторы, службы и предметная история сохраняются.
ALTER TABLE `defects`
    ADD COLUMN `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `archived_at` DATETIME DEFAULT NULL;
CREATE INDEX `idx_defects_archive_status` ON `defects` (`is_archived`, `status`, `id`);
