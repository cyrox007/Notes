-- Messenger retention timestamps for soft-deleted attachments.
-- Safe on both upgraded Beta4 schemas and current fresh-install schemas.

SET @messenger_retention_has_deleted_at = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'messenger_attachments'
      AND column_name = 'deleted_at'
);
SET @messenger_retention_column_sql = IF(
    @messenger_retention_has_deleted_at = 0,
    'ALTER TABLE messenger_attachments ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER is_deleted',
    'SELECT 1'
);
PREPARE messenger_retention_column_stmt FROM @messenger_retention_column_sql;
EXECUTE messenger_retention_column_stmt;
DEALLOCATE PREPARE messenger_retention_column_stmt;

SET @messenger_retention_has_index = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'messenger_attachments'
      AND index_name = 'idx_messenger_attachments_deleted'
);
SET @messenger_retention_index_sql = IF(
    @messenger_retention_has_index = 0,
    'ALTER TABLE messenger_attachments ADD KEY idx_messenger_attachments_deleted (is_deleted, deleted_at)',
    'SELECT 1'
);
PREPARE messenger_retention_index_stmt FROM @messenger_retention_index_sql;
EXECUTE messenger_retention_index_stmt;
DEALLOCATE PREPARE messenger_retention_index_stmt;

-- Existing soft-deleted rows begin retention at upgrade time.
UPDATE messenger_attachments
SET deleted_at = CURRENT_TIMESTAMP
WHERE is_deleted = 1 AND deleted_at IS NULL;
