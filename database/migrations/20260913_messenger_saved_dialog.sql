ALTER TABLE `dialogs`
    MODIFY COLUMN `type` ENUM('private', 'group', 'saved') NOT NULL DEFAULT 'private';
