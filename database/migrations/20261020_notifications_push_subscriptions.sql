-- Notes 1.2: привязки браузерных устройств для Web Push.
-- Endpoint и ключи не выводятся в админку и системные журналы.
CREATE TABLE IF NOT EXISTS `notification_push_subscriptions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `endpoint_hash` BINARY(32) NOT NULL,
    `endpoint` TEXT NOT NULL,
    `p256dh` VARCHAR(180) NOT NULL,
    `auth` VARCHAR(100) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `revoked_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notification_push_endpoint` (`endpoint_hash`),
    KEY `idx_notification_push_owner` (`user_id`, `revoked_at`),
    CONSTRAINT `fk_notification_push_user`
      FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
