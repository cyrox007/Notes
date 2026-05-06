-- ============================================
-- Мессенджер: Структура базы данных
-- Версия: 1.0
-- Описание: Таблицы для системы диалогов, сообщений и пользователей
-- ============================================

-- --------------------------------------------
-- 1. Таблица пользователей (users)
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `firstname` VARCHAR(50) NOT NULL,
    `lastname` VARCHAR(50) NOT NULL,
    `avatar` VARCHAR(255) DEFAULT NULL,
    `role` INT DEFAULT 888,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_username` (`username`),
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------
-- 2. Таблица диалогов (dialogs)
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS `dialogs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `uid` VARCHAR(64) UNIQUE DEFAULT NULL,
    `type` ENUM('private', 'group') NOT NULL DEFAULT 'private',
    `name` VARCHAR(100) DEFAULT NULL COMMENT 'Название для групповых чатов',
    `created_by` INT NOT NULL COMMENT 'Создатель диалога',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_type` (`type`),
    INDEX `idx_created_by` (`created_by`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------
-- 3. Таблица связей пользователей с диалогами (dialog_users)
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS `dialog_users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `dialog_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `role` ENUM('admin', 'member') NOT NULL DEFAULT 'member',
    `joined_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `last_read_message_id` INT DEFAULT 0 COMMENT 'ID последнего прочитанного сообщения',
    `is_deleted` TINYINT(1) DEFAULT 0 COMMENT 'Пользователь покинул диалог',
    UNIQUE KEY `unique_dialog_user` (`dialog_id`, `user_id`),
    INDEX `idx_dialog_id` (`dialog_id`),
    INDEX `idx_user_id` (`user_id`),
    FOREIGN KEY (`dialog_id`) REFERENCES `dialogs`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------
-- 4. Таблица сообщений (messages)
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `uid` VARCHAR(64) UNIQUE DEFAULT NULL,
    `dialog_id` INT NOT NULL,
    `sender_id` INT NOT NULL,
    `content` TEXT NOT NULL COMMENT 'Зашифрованный контент сообщения',
    `content_type` ENUM('text', 'image', 'audio', 'video', 'file', 'voice') NOT NULL DEFAULT 'text',
    `meta_data` JSON DEFAULT NULL COMMENT 'Дополнительные данные (ссылки на файлы, длительность и т.д.)',
    `message_status` ENUM('sent', 'delivered', 'read') DEFAULT 'sent',
    `is_deleted` TINYINT(1) DEFAULT 0 COMMENT 'Сообщение удалено (safe delete)',
    `edited_at` DATETIME DEFAULT NULL COMMENT 'Время редактирования',
    `deleted_at` DATETIME DEFAULT NULL COMMENT 'Время удаления',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_dialog_id` (`dialog_id`),
    INDEX `idx_sender_id` (`sender_id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_is_deleted` (`is_deleted`),
    FOREIGN KEY (`dialog_id`) REFERENCES `dialogs`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`sender_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------
-- 5. Таблица статусов сообщений для получателей (message_statuses)
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS `message_statuses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `status` ENUM('sent', 'delivered', 'read') NOT NULL DEFAULT 'sent',
    `read_at` DATETIME DEFAULT NULL,
    UNIQUE KEY `unique_message_user` (`message_id`, `user_id`),
    INDEX `idx_message_id` (`message_id`),
    INDEX `idx_user_id` (`user_id`),
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------
-- Индексы для оптимизации производительности
-- --------------------------------------------

-- Индекс для быстрого поиска непрочитанных сообщений
CREATE INDEX `idx_unread_messages` ON `messages`(`dialog_id`, `sender_id`, `is_deleted`, `created_at`);

-- Индекс для быстрого получения последних сообщений в диалоге
CREATE INDEX `idx_dialog_last_messages` ON `messages`(`dialog_id`, `created_at` DESC);

-- --------------------------------------------
-- Триггеры для автоматического обновления
-- --------------------------------------------

-- Триггер для обновления updated_at в dialogs при добавлении сообщения
DELIMITER $$
CREATE TRIGGER `update_dialog_after_message_insert`
AFTER INSERT ON `messages`
FOR EACH ROW
BEGIN
    UPDATE `dialogs` SET `updated_at` = CURRENT_TIMESTAMP WHERE `id` = NEW.dialog_id;
END$$
DELIMITER ;

-- --------------------------------------------
-- Начальные данные (демонстрационные пользователи)
-- --------------------------------------------

-- Пароли зашифрованы через password_hash() в PHP
-- user1 / password123
-- user2 / password123
INSERT INTO `users` (`username`, `email`, `password_hash`, `firstname`, `lastname`, `role`) VALUES
('user1', 'user1@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Иван', 'Иванов', 888),
('user2', 'user2@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Петр', 'Петров', 888),
('user3', 'user3@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Анна', 'Смирнова', 888);

-- --------------------------------------------
-- Примечания по безопасности
-- --------------------------------------------
-- 
-- 1. Все текстовые сообщения шифруются на уровне приложения (AES-256-CBC)
--    перед сохранением в поле `content`. Ключ хранится в переменной окружения MSG_SECRET_KEY.
--
-- 2. Медиафайлы загружаются в защищенную дирекорию и могут быть зашифрованы отдельно.
--
-- 3. Для полноценного E2EE (End-to-End Encryption) необходимо реализовать:
--    - Генерацию ключей на стороне клиента
--    - Обмен публичными ключами через сервер
--    - Шифрование на клиенте перед отправкой
--
-- 4. Safe-удаление: сообщения помечаются флагом `is_deleted`, но не удаляются физически.
--    Это позволяет отображать "[Сообщение удалено]" вместо контента.
--
-- 5. Регулярно делайте бэкапы базы данных и храните их в зашифрованном виде.
--
