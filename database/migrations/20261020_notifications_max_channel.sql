-- Notes 1.2: отдельная подписка MAX, не связанная с Telegram.
-- Только существующие установки: новый инсталлятор получает колонку из базовой схемы.
ALTER TABLE notification_subscriptions
    ADD COLUMN max_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER telegram_enabled;
