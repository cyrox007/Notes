-- Notes retention timestamps for soft-deleted attachments.
ALTER TABLE note_attachments
    ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER is_deleted,
    ADD KEY idx_note_attachments_deleted (is_deleted, deleted_at);

-- Existing soft-deleted rows predate this timestamp contract. Start their
-- retention clock at upgrade time rather than purging them immediately.
UPDATE note_attachments
SET deleted_at = CURRENT_TIMESTAMP
WHERE is_deleted = 1 AND deleted_at IS NULL;
