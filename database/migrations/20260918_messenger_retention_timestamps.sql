-- Messenger retention timestamps for soft-deleted attachments.
ALTER TABLE messenger_attachments
    ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER is_deleted,
    ADD KEY idx_messenger_attachments_deleted (is_deleted, deleted_at);

-- Existing soft-deleted rows begin retention at upgrade time.
UPDATE messenger_attachments
SET deleted_at = CURRENT_TIMESTAMP
WHERE is_deleted = 1 AND deleted_at IS NULL;
