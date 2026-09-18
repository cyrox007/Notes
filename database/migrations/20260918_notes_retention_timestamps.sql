-- Notes retention timestamps for soft-deleted attachments.
-- Safe on both upgraded Beta4 schemas and current fresh-install schemas.

SET @notes_retention_has_deleted_at = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'note_attachments'
      AND column_name = 'deleted_at'
);
SET @notes_retention_column_sql = IF(
    @notes_retention_has_deleted_at = 0,
    'ALTER TABLE note_attachments ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER is_deleted',
    'SELECT 1'
);
PREPARE notes_retention_column_stmt FROM @notes_retention_column_sql;
EXECUTE notes_retention_column_stmt;
DEALLOCATE PREPARE notes_retention_column_stmt;

SET @notes_retention_has_index = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'note_attachments'
      AND index_name = 'idx_note_attachments_deleted'
);
SET @notes_retention_index_sql = IF(
    @notes_retention_has_index = 0,
    'ALTER TABLE note_attachments ADD KEY idx_note_attachments_deleted (is_deleted, deleted_at)',
    'SELECT 1'
);
PREPARE notes_retention_index_stmt FROM @notes_retention_index_sql;
EXECUTE notes_retention_index_stmt;
DEALLOCATE PREPARE notes_retention_index_stmt;

-- Existing soft-deleted rows predate this timestamp contract. Start their
-- retention clock at upgrade time rather than purging them immediately.
UPDATE note_attachments
SET deleted_at = CURRENT_TIMESTAMP
WHERE is_deleted = 1 AND deleted_at IS NULL;
