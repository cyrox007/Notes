-- Messenger retention timestamps for soft-deleted attachments.
ALTER TABLE messenger_attachments
    ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER is_deleted,
    ADD KEY idx_messenger_attachments_deleted (is_deleted, deleted_at);
