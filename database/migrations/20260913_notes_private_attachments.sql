-- Notes private attachment contract.
-- Existing rows are NOT rewritten: some installations may contain historical
-- files with unknown encryption provenance and require an explicit audit.

ALTER TABLE `note_attachments`
    MODIFY COLUMN `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 only when file bytes are actually encrypted; hardened uploads currently use private storage + ACL and write 0';
