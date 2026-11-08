-- Основа обмена открытыми ключами и непрозрачными пакетами Olm.
-- Секретные ключи и открытые сообщения в этих таблицах не допускаются.
-- Применять штатным механизмом миграций с DBPREFIX.

CREATE TABLE IF NOT EXISTS `messenger_e2ee_devices` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `device_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `signing_key` VARCHAR(128) NOT NULL,
  `agreement_key` VARCHAR(128) NOT NULL,
  `identity_json` JSON NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uq_messenger_e2ee_device` (`user_id`, `device_id`),
  KEY `idx_messenger_e2ee_active` (`user_id`, `revoked_at`),
  CONSTRAINT `fk_messenger_e2ee_devices_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messenger_e2ee_prekeys` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `device_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `key_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `key_json` JSON NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `claimed_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uq_messenger_e2ee_prekey` (`user_id`, `device_id`, `key_id`),
  KEY `idx_messenger_e2ee_prekey_claim` (`user_id`, `device_id`, `claimed_at`, `id`),
  CONSTRAINT `fk_messenger_e2ee_prekeys_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messenger_e2ee_mailbox` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sender_user_id` INT NOT NULL,
  `sender_device_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `recipient_user_id` INT NOT NULL,
  `recipient_device_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dialog_id` BIGINT UNSIGNED NOT NULL,
  `transaction_id` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `envelope_json` JSON NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  `acknowledged_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uq_messenger_e2ee_delivery` (
    `sender_user_id`, `sender_device_id`, `recipient_user_id`, `recipient_device_id`, `transaction_id`
  ),
  KEY `idx_messenger_e2ee_inbox` (`recipient_user_id`, `recipient_device_id`, `id`),
  KEY `idx_messenger_e2ee_expiry` (`expires_at`),
  CONSTRAINT `fk_messenger_e2ee_sender` FOREIGN KEY (`sender_user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messenger_e2ee_recipient` FOREIGN KEY (`recipient_user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messenger_e2ee_dialog` FOREIGN KEY (`dialog_id`)
    REFERENCES `dialogs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Отдельная хронология: сервер не расшифровывает и не индексирует содержимое.
CREATE TABLE IF NOT EXISTS `messenger_e2ee_events` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `dialog_id` BIGINT UNSIGNED NOT NULL,
  `sender_user_id` INT NOT NULL,
  `sender_device_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `transaction_id` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `encrypted_json` JSON NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_messenger_e2ee_room_txn` (`dialog_id`, `sender_user_id`, `sender_device_id`, `transaction_id`),
  KEY `idx_messenger_e2ee_room_events` (`dialog_id`, `id`),
  CONSTRAINT `fk_messenger_e2ee_event_dialog` FOREIGN KEY (`dialog_id`)
    REFERENCES `dialogs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messenger_e2ee_event_user` FOREIGN KEY (`sender_user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Защищённые вложения: сервер хранит исключительно шифротекст, без имени,
-- MIME-описания, секретного ключа и содержимого исходного файла.
CREATE TABLE IF NOT EXISTS `messenger_e2ee_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `uid` CHAR(36) NOT NULL,
  `dialog_id` BIGINT UNSIGNED NOT NULL,
  `uploader_user_id` INT NOT NULL,
  `event_id` BIGINT UNSIGNED DEFAULT NULL,
  `stored_path` VARCHAR(768) NOT NULL,
  `size` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_messenger_e2ee_attachment_uid` (`uid`),
  KEY `idx_messenger_e2ee_attachment_event` (`event_id`),
  KEY `idx_messenger_e2ee_attachment_orphan` (`event_id`, `created_at`),
  CONSTRAINT `fk_messenger_e2ee_attachment_dialog`
    FOREIGN KEY (`dialog_id`) REFERENCES `dialogs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messenger_e2ee_attachment_sender`
    FOREIGN KEY (`uploader_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messenger_e2ee_attachment_event`
    FOREIGN KEY (`event_id`) REFERENCES `messenger_e2ee_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
