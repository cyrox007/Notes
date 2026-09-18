-- ============================================
-- Заметки (Notes): каноническая структура БД
-- Версия: 2.3 - explicit profile publication
-- ============================================

CREATE TABLE IF NOT EXISTS `notes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `uid` VARCHAR(64) UNIQUE NOT NULL COMMENT 'Уникальный идентификатор заметки (hex)',
    `user_id` INT NOT NULL COMMENT 'Владелец заметки',
    `notename` VARCHAR(255) NOT NULL COMMENT 'Название заметки',
    `content` TEXT DEFAULT NULL COMMENT 'Зашифрованный текстовый контент заметки',
    `content_type` ENUM('text', 'image', 'audio', 'video', 'file', 'voice') NOT NULL DEFAULT 'text',
    `is_encrypted` TINYINT(1) DEFAULT 1 COMMENT 'Флаг шифрования текстового контента',
    `created_note` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_note` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `is_deleted` TINYINT(1) DEFAULT 0 COMMENT 'Soft-delete заметки',
    `is_profile_public` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Явно опубликовано владельцем в публичном профиле',
    `deleted_at` DATETIME DEFAULT NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_uid` (`uid`),
    INDEX `idx_created_note` (`created_note`),
    INDEX `idx_is_deleted` (`is_deleted`),
    INDEX `idx_user_notes` (`user_id`, `is_deleted`, `created_note` DESC),
    INDEX `idx_updated_notes` (`user_id`, `updated_note` DESC),
    INDEX `idx_notes_profile_public` (`user_id`, `is_profile_public`, `is_deleted`, `updated_note` DESC),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Личные заметки пользователей';

CREATE TABLE IF NOT EXISTS `note_attachments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `note_id` INT NOT NULL,
    `file_uid` VARCHAR(64) UNIQUE NOT NULL,
    `file_name` VARCHAR(255) NOT NULL COMMENT 'Безопасно нормализованное оригинальное имя',
    `file_path` VARCHAR(512) NOT NULL COMMENT 'Серверный путь внутри PRIVATE_STORAGE_PATH/notes; клиенту не выдаётся',
    `file_type` ENUM('image', 'audio', 'video', 'document', 'voice') NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL COMMENT 'MIME, определённый сервером через finfo',
    `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `duration` INT DEFAULT NULL,
    `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 только если байты файла реально зашифрованы; новые файлы пока 0',
    `encryption_key_ref` VARCHAR(64) DEFAULT NULL,
    `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `deleted_at` DATETIME DEFAULT NULL,
    INDEX `idx_note_id` (`note_id`),
    INDEX `idx_note_attachments_deleted` (`is_deleted`, `deleted_at`),
    INDEX `idx_file_uid` (`file_uid`),
    INDEX `idx_file_type` (`file_type`),
    INDEX `idx_notes_with_attachments` (`note_id`, `file_type`),
    FOREIGN KEY (`note_id`) REFERENCES `notes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Приватные вложения заметок';

CREATE TABLE IF NOT EXISTS `shared_notes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `note_id` INT NOT NULL,
    `owner_id` INT NOT NULL,
    `shared_with_user_id` INT DEFAULT NULL,
    `share_token` VARCHAR(64) UNIQUE NOT NULL,
    `access_type` ENUM('view', 'edit') NOT NULL DEFAULT 'view',
    `expires_at` DATETIME DEFAULT NULL,
    `shared_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `is_active` TINYINT(1) DEFAULT 1,
    INDEX `idx_note_id` (`note_id`),
    INDEX `idx_share_token` (`share_token`),
    INDEX `idx_owner_id` (`owner_id`),
    INDEX `idx_shared_with` (`shared_with_user_id`),
    FOREIGN KEY (`note_id`) REFERENCES `notes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`shared_with_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Общий доступ к заметкам';

CREATE TABLE IF NOT EXISTS `note_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `note_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `action` ENUM('create', 'update', 'delete', 'restore', 'share', 'unshare') NOT NULL,
    `old_content` TEXT DEFAULT NULL,
    `new_content` TEXT DEFAULT NULL,
    `changed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_note_id` (`note_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_changed_at` (`changed_at`),
    FOREIGN KEY (`note_id`) REFERENCES `notes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='История изменений заметок';

CREATE TABLE IF NOT EXISTS `note_tags` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `tag_name` VARCHAR(50) NOT NULL,
    `color` VARCHAR(7) DEFAULT '#000000',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_user_tag` (`user_id`, `tag_name`),
    INDEX `idx_user_id` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Теги заметок';

CREATE TABLE IF NOT EXISTS `note_tag_relations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `note_id` INT NOT NULL,
    `tag_id` INT NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_note_tag` (`note_id`, `tag_id`),
    INDEX `idx_note_id` (`note_id`),
    INDEX `idx_tag_id` (`tag_id`),
    FOREIGN KEY (`note_id`) REFERENCES `notes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`tag_id`) REFERENCES `note_tags`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Связи заметок с тегами';

-- Canonical fresh schema avoids mysql-client-only DELIMITER directives so it can
-- be imported safely by the browser installer as well as mysql/phpMyAdmin.
DROP TRIGGER IF EXISTS `note_after_insert`;
CREATE TRIGGER `note_after_insert`
AFTER INSERT ON `notes`
FOR EACH ROW
INSERT INTO `note_history` (`note_id`, `user_id`, `action`, `new_content`, `changed_at`)
VALUES (NEW.id, NEW.user_id, 'create', NEW.content, CURRENT_TIMESTAMP);

DROP TRIGGER IF EXISTS `note_after_update`;
CREATE TRIGGER `note_after_update`
AFTER UPDATE ON `notes`
FOR EACH ROW
INSERT INTO `note_history` (`note_id`, `user_id`, `action`, `old_content`, `new_content`, `changed_at`)
SELECT NEW.id, NEW.user_id, 'update', OLD.content, NEW.content, CURRENT_TIMESTAMP
WHERE NOT (OLD.content <=> NEW.content) OR OLD.is_deleted != NEW.is_deleted;

-- ============================================
-- Security contract
-- ============================================
-- 1. Текст заметки:
--    CryptMethods использует UNIQUE_KEY и XChaCha20-Poly1305 (libsodium).
--    Ошибки crypto являются fail-closed: plaintext fallback запрещён.
--
-- 2. Вложения:
--    Новые файлы сохраняются только под PRIVATE_STORAGE_PATH/notes вне document root.
--    Расширение сверяется с реальным MIME через finfo.
--    Браузеру не выдаётся file_path; чтение идёт через ACL endpoints.
--    На текущем этапе байты вложений НЕ шифруются at-rest, поэтому is_encrypted=0.
--
-- 3. Общий доступ и профиль:
--    Публичная share-ссылка использует случайный share_token, is_active и expires_at.
--    is_profile_public — отдельное явное решение владельца и НЕ выводится из share-token.
--    Публикация в профиле по умолчанию выключена и не открывает вложения автоматически.
--
-- 4. Soft delete:
--    Notes/attachments используют is_deleted. Политику физической очистки следует
--    выполнять отдельной задачей после определения retention/backup требований.
