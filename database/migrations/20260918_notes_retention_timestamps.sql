-- Notes retention timestamps for soft-deleted attachments.
ALTER TABLE note_attachments
    ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER is_deleted,
    ADD KEY idx_note_attachments_deleted (is_deleted, deleted_at);
