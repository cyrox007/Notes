-- Оптимистическая блокировка изменяемых сущностей Tasks 1.2. Идемпотентно.
SET @tasks_version_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tasks' AND COLUMN_NAME='version')=0,
    'ALTER TABLE `tasks` ADD COLUMN `version` BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER `deleted_at`',
    'SELECT 1'
);
PREPARE tasks_version_stmt FROM @tasks_version_ddl;
EXECUTE tasks_version_stmt;
DEALLOCATE PREPARE tasks_version_stmt;

SET @board_items_version_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_board_items' AND COLUMN_NAME='version')=0,
    'ALTER TABLE `task_board_items` ADD COLUMN `version` BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER `deleted_at`',
    'SELECT 1'
);
PREPARE board_items_version_stmt FROM @board_items_version_ddl;
EXECUTE board_items_version_stmt;
DEALLOCATE PREPARE board_items_version_stmt;
