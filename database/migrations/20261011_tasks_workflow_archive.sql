-- Five-stage workflow. Archive preserves the last active status. Idempotent.
ALTER TABLE `tasks` MODIFY `status` ENUM('pending','studying','in_progress','review','completed','cancelled') NOT NULL DEFAULT 'pending';
SET @archive_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tasks' AND COLUMN_NAME='archived_at')=0, 'ALTER TABLE `tasks` ADD COLUMN `archived_at` DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE archive_stmt FROM @archive_ddl;
EXECUTE archive_stmt;
DEALLOCATE PREPARE archive_stmt;
UPDATE `tasks` SET archived_at=COALESCE(archived_at,updated_at,CURRENT_TIMESTAMP), status='pending' WHERE status='cancelled';
ALTER TABLE `task_board_items` MODIFY `status` ENUM('pending','studying','in_progress','review','completed','cancelled') NOT NULL DEFAULT 'pending';
SET @archive_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_board_items' AND COLUMN_NAME='archived_at')=0, 'ALTER TABLE `task_board_items` ADD COLUMN `archived_at` DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE archive_stmt FROM @archive_ddl;
EXECUTE archive_stmt;
DEALLOCATE PREPARE archive_stmt;
UPDATE `task_board_items` SET archived_at=COALESCE(archived_at,updated_at,CURRENT_TIMESTAMP), status='pending' WHERE status='cancelled';
