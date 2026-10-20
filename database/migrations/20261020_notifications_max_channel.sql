-- Notes 1.2: отдельная подписка MAX. Без изменения чужих таблиц.
-- Для новой установки колонка уже есть в notifications_schema.sql.
-- Старые установки добавляют её только при отсутствии, в том числе при DBPREFIX.
SET @notes_max_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'notification_subscriptions'
          AND column_name = 'max_enabled'
    ),
    'SELECT 1',
    'ALTER TABLE notification_subscriptions ADD COLUMN max_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER telegram_enabled'
);
PREPARE notes_max_stmt FROM @notes_max_sql;
EXECUTE notes_max_stmt;
DEALLOCATE PREPARE notes_max_stmt;
