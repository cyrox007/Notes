-- ============================================
-- Заметки (Notes): Структура базы данных
-- Версия: 2.0 - с поддержкой медиа и голосовых заметок
-- Описание: Таблицы для системы личных заметок с поддержкой текста, медиа, аудио и голосовых сообщений
-- ============================================

-- --------------------------------------------
-- 1. Таблица личных заметок (notes)
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS `notes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `uid` VARCHAR(64) UNIQUE NOT NULL COMMENT 'Уникальный идентификатор заметки (hex)',
    `user_id` INT NOT NULL COMMENT 'Владелец заметки',
    `notename` VARCHAR(255) NOT NULL COMMENT 'Название заметки',
    `content` TEXT DEFAULT NULL COMMENT 'Зашифрованный текстовый контент заметки',
    `content_type` ENUM('text', 'image', 'audio', 'video', 'file', 'voice') NOT NULL DEFAULT 'text' COMMENT 'Тип основного контента',
    `is_encrypted` TINYINT(1) DEFAULT 1 COMMENT 'Флаг шифрования контента',
    `created_note` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_note` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `is_deleted` TINYINT(1) DEFAULT 0 COMMENT 'Safe-удаление заметки',
    `deleted_at` DATETIME DEFAULT NULL COMMENT 'Время удаления',
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_uid` (`uid`),
    INDEX `idx_created_note` (`created_note`),
    INDEX `idx_is_deleted` (`is_deleted`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Таблица личных заметок пользователей';

-- --------------------------------------------
-- 2. Таблица медиа-вложений заметок (note_attachments)
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS `note_attachments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `note_id` INT NOT NULL COMMENT 'ID заметки, к которой прикреплен файл',
    `file_uid` VARCHAR(64) UNIQUE NOT NULL COMMENT 'Уникальный идентификатор файла',
    `file_name` VARCHAR(255) NOT NULL COMMENT 'Оригинальное имя файла',
    `file_path` VARCHAR(512) NOT NULL COMMENT 'Путь к файлу на сервере',
    `file_type` ENUM('image', 'audio', 'video', 'document', 'voice') NOT NULL COMMENT 'Тип файла',
    `mime_type` VARCHAR(100) NOT NULL COMMENT 'MIME тип файла',
    `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Размер файла в байтах',
    `duration` INT DEFAULT NULL COMMENT 'Длительность аудио/видео в секундах (для voice/audio/video)',
    `is_encrypted` TINYINT(1) DEFAULT 1 COMMENT 'Флаг шифрования файла',
    `encryption_key_ref` VARCHAR(64) DEFAULT NULL COMMENT 'Ссылка на ключ шифрования (опционально)',
    `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `is_deleted` TINYINT(1) DEFAULT 0 COMMENT 'Safe-удаление вложения',
    INDEX `idx_note_id` (`note_id`),
    INDEX `idx_file_uid` (`file_uid`),
    INDEX `idx_file_type` (`file_type`),
    FOREIGN KEY (`note_id`) REFERENCES `notes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Вложения к заметкам (медиа, аудио, файлы)';

-- --------------------------------------------
-- 3. Таблица общих заметок (shared_notes)
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS `shared_notes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `note_id` INT NOT NULL COMMENT 'ID заметки которой делятся',
    `owner_id` INT NOT NULL COMMENT 'ID владельца заметки',
    `shared_with_user_id` INT DEFAULT NULL COMMENT 'ID пользователя с которым делятся (NULL = публичная ссылка)',
    `share_token` VARCHAR(64) UNIQUE NOT NULL COMMENT 'Токен для доступа по ссылке',
    `access_type` ENUM('view', 'edit') NOT NULL DEFAULT 'view' COMMENT 'Тип доступа',
    `expires_at` DATETIME DEFAULT NULL COMMENT 'Время истечения срока доступа',
    `shared_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `is_active` TINYINT(1) DEFAULT 1 COMMENT 'Активна ли ссылка',
    INDEX `idx_note_id` (`note_id`),
    INDEX `idx_share_token` (`share_token`),
    INDEX `idx_owner_id` (`owner_id`),
    INDEX `idx_shared_with` (`shared_with_user_id`),
    FOREIGN KEY (`note_id`) REFERENCES `notes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`shared_with_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Общий доступ к заметкам';

-- --------------------------------------------
-- 4. Таблица истории изменений заметок (note_history)
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS `note_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `note_id` INT NOT NULL COMMENT 'ID заметки',
    `user_id` INT NOT NULL COMMENT 'ID пользователя внесшего изменения',
    `action` ENUM('create', 'update', 'delete', 'restore', 'share', 'unshare') NOT NULL COMMENT 'Тип действия',
    `old_content` TEXT DEFAULT NULL COMMENT 'Предыдущий контент (зашифрованный)',
    `new_content` TEXT DEFAULT NULL COMMENT 'Новый контент (зашифрованный)',
    `changed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_note_id` (`note_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_changed_at` (`changed_at`),
    FOREIGN KEY (`note_id`) REFERENCES `notes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='История изменений заметок';

-- --------------------------------------------
-- 5. Таблица тегов для заметок (note_tags)
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS `note_tags` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL COMMENT 'Владелец тега',
    `tag_name` VARCHAR(50) NOT NULL COMMENT 'Название тега',
    `color` VARCHAR(7) DEFAULT '#000000' COMMENT 'Цвет тега (hex)',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_user_tag` (`user_id`, `tag_name`),
    INDEX `idx_user_id` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Теги для заметок';

-- --------------------------------------------
-- 6. Таблица связей заметок и тегов (note_tag_relations)
-- --------------------------------------------
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

-- --------------------------------------------
-- Индексы для оптимизации производительности
-- --------------------------------------------

-- Индекс для быстрого поиска заметок пользователя
CREATE INDEX `idx_user_notes` ON `notes`(`user_id`, `is_deleted`, `created_note` DESC);

-- Индекс для поиска по дате обновления
CREATE INDEX `idx_updated_notes` ON `notes`(`user_id`, `updated_note` DESC);

-- Индекс для поиска заметок с вложениями
CREATE INDEX `idx_notes_with_attachments` ON `note_attachments`(`note_id`, `file_type`);

-- --------------------------------------------
-- Триггеры для автоматического ведения истории
-- --------------------------------------------

-- Триггер для записи истории при создании заметки
DELIMITER $$
CREATE TRIGGER `note_after_insert`
AFTER INSERT ON `notes`
FOR EACH ROW
BEGIN
    INSERT INTO `note_history` (`note_id`, `user_id`, `action`, `new_content`, `changed_at`)
    VALUES (NEW.id, NEW.user_id, 'create', NEW.content, CURRENT_TIMESTAMP);
END$$
DELIMITER ;

-- Триггер для записи истории при обновлении заметки
DELIMITER $$
CREATE TRIGGER `note_after_update`
AFTER UPDATE ON `notes`
FOR EACH ROW
BEGIN
    IF OLD.content != NEW.content OR OLD.is_deleted != NEW.is_deleted THEN
        INSERT INTO `note_history` (`note_id`, `user_id`, `action`, `old_content`, `new_content`, `changed_at`)
        VALUES (NEW.id, NEW.user_id, 'update', OLD.content, NEW.content, CURRENT_TIMESTAMP);
    END IF;
END$$
DELIMITER ;

-- --------------------------------------------
-- Примечания по безопасности и использованию
-- --------------------------------------------
-- 
-- 1. Шифрование контента:
--    - Текстовый контент заметок шифруется через AES-256-CBC
--    - Ключ шифрования хранится в переменной окружения NOTE_SECRET_KEY
--    - Медиафайлы могут быть зашифрованы отдельно с использованием уникальных ключей
--
-- 2. Голосовые заметки:
--    - Записываются в формате audio/webm или audio/mp3
--    - Сохраняются как вложения с file_type = 'voice'
--    - Длительность записи хранится в поле duration (секунды)
--
-- 3. Медиа-вложения:
--    - Изображения: image/jpeg, image/png, image/gif, image/webp
--    - Аудио: audio/mp3, audio/wav, audio/ogg
--    - Видео: video/mp4, video/webm, video/avi
--    - Документы: application/pdf, text/plain, и др.
--
-- 4. Общий доступ через мессенджер:
--    - Создаётся запись в shared_notes с токеном доступа
--    - Токен можно отправить через мессенджер как ссылку
--    - Поддерживается доступ по ссылке (shared_with_user_id = NULL)
--    - Можно установить срок действия (expires_at)
--
-- 5. Safe-удаление:
--    - Заметки и вложения помечаются флагом is_deleted
--    - Физическое удаление не производится для возможности восстановления
--    - История изменений сохраняется для аудита
--
-- 6. Ограничения:
--    - Максимальный размер файла: настраивается в .env (MAX_UPLOAD_SIZE)
--    - Максимальное количество вложений на заметку: рекомендуется ограничить на уровне приложения
--    - Срок хранения удалённых заметок: настраивается политикой очистки
--
-- 7. Рекомендации по хранению файлов:
--    - Храните файлы вне корневой директории веб-сервера
--    - Используйте защищённую директорию с ограниченным доступом
--    - Для продакшена настройте отдельный файловый сервер или S3-хранилище
--
