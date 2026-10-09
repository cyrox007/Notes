-- Жизненный цикл пользовательского аккаунта.
-- Физическое удаление строки users опасно: на неё ссылаются сообщения,
-- общие задачи и другие совместные объекты. Поэтому после периода хранения
-- персональные данные обезличиваются, а технический tombstone остаётся.
--
-- Миграция допускает уже частично синхронизированную схему: если нужные поля
-- или индекс уже существуют в совместимом виде, они не создаются повторно.
-- Несовместимое состояние отклоняется до изменения таблицы.

-- Хостинг не обязан разрешать CREATE ROUTINE / ALTER ROUTINE / EXECUTE.
-- Условные изменения выполняются через подготовленные обычные запросы.
-- При несовместимости PREPARE заведомо неверного SELECT останавливает миграцию
-- до первого ALTER; ссылка на отсутствующую колонку содержит причину отказа.

SET @workspace_lifecycle_sql = IF(
    NOT EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'users'
    ),
    'SELECT `Не найдена таблица users для миграции жизненного цикла` FROM (SELECT 1 AS valid_schema) AS updater_schema_guard',
    'SELECT 1'
);
PREPARE workspace_lifecycle_stmt FROM @workspace_lifecycle_sql;
EXECUTE workspace_lifecycle_stmt;
DEALLOCATE PREPARE workspace_lifecycle_stmt;

SET @workspace_lifecycle_sql = IF(
    NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'account_status'
    ),
    'SELECT `В users отсутствует обязательное поле account_status` FROM (SELECT 1 AS valid_schema) AS updater_schema_guard',
    'SELECT 1'
);
PREPARE workspace_lifecycle_stmt FROM @workspace_lifecycle_sql;
EXECUTE workspace_lifecycle_stmt;
DEALLOCATE PREPARE workspace_lifecycle_stmt;

SET @workspace_lifecycle_sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'deletion_requested_at'
    ) AND NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'deletion_requested_at'
          AND data_type = 'datetime'
          AND is_nullable = 'YES'
    ),
    'SELECT `Несовместимое поле users.deletion_requested_at` FROM (SELECT 1 AS valid_schema) AS updater_schema_guard',
    'SELECT 1'
);
PREPARE workspace_lifecycle_stmt FROM @workspace_lifecycle_sql;
EXECUTE workspace_lifecycle_stmt;
DEALLOCATE PREPARE workspace_lifecycle_stmt;

SET @workspace_lifecycle_sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'purge_after'
    ) AND NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'purge_after'
          AND data_type = 'datetime'
          AND is_nullable = 'YES'
    ),
    'SELECT `Несовместимое поле users.purge_after` FROM (SELECT 1 AS valid_schema) AS updater_schema_guard',
    'SELECT 1'
);
PREPARE workspace_lifecycle_stmt FROM @workspace_lifecycle_sql;
EXECUTE workspace_lifecycle_stmt;
DEALLOCATE PREPARE workspace_lifecycle_stmt;

SET @workspace_lifecycle_sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'anonymized_at'
    ) AND NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'anonymized_at'
          AND data_type = 'datetime'
          AND is_nullable = 'YES'
    ),
    'SELECT `Несовместимое поле users.anonymized_at` FROM (SELECT 1 AS valid_schema) AS updater_schema_guard',
    'SELECT 1'
);
PREPARE workspace_lifecycle_stmt FROM @workspace_lifecycle_sql;
EXECUTE workspace_lifecycle_stmt;
DEALLOCATE PREPARE workspace_lifecycle_stmt;

SET @workspace_lifecycle_sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND index_name = 'idx_users_purge'
    ) AND (
        (SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = 'users'
           AND index_name = 'idx_users_purge') <> 2
        OR NOT EXISTS (
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'users'
              AND index_name = 'idx_users_purge'
              AND seq_in_index = 1
              AND column_name = 'purge_after'
        )
        OR NOT EXISTS (
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'users'
              AND index_name = 'idx_users_purge'
              AND seq_in_index = 2
              AND column_name = 'anonymized_at'
        )
    ),
    'SELECT `Несовместимый индекс users.idx_users_purge` FROM (SELECT 1 AS valid_schema) AS updater_schema_guard',
    'SELECT 1'
);
PREPARE workspace_lifecycle_stmt FROM @workspace_lifecycle_sql;
EXECUTE workspace_lifecycle_stmt;
DEALLOCATE PREPARE workspace_lifecycle_stmt;

SET @workspace_lifecycle_sql = IF(
    NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'deletion_requested_at'
    ),
    'ALTER TABLE `users` ADD COLUMN `deletion_requested_at` DATETIME NULL AFTER `account_status`',
    'SELECT 1'
);
PREPARE workspace_lifecycle_stmt FROM @workspace_lifecycle_sql;
EXECUTE workspace_lifecycle_stmt;
DEALLOCATE PREPARE workspace_lifecycle_stmt;

SET @workspace_lifecycle_sql = IF(
    NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'purge_after'
    ),
    'ALTER TABLE `users` ADD COLUMN `purge_after` DATETIME NULL AFTER `deletion_requested_at`',
    'SELECT 1'
);
PREPARE workspace_lifecycle_stmt FROM @workspace_lifecycle_sql;
EXECUTE workspace_lifecycle_stmt;
DEALLOCATE PREPARE workspace_lifecycle_stmt;

SET @workspace_lifecycle_sql = IF(
    NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'anonymized_at'
    ),
    'ALTER TABLE `users` ADD COLUMN `anonymized_at` DATETIME NULL AFTER `purge_after`',
    'SELECT 1'
);
PREPARE workspace_lifecycle_stmt FROM @workspace_lifecycle_sql;
EXECUTE workspace_lifecycle_stmt;
DEALLOCATE PREPARE workspace_lifecycle_stmt;

SET @workspace_lifecycle_sql = IF(
    NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND index_name = 'idx_users_purge'
    ),
    'ALTER TABLE `users` ADD KEY `idx_users_purge` (`purge_after`, `anonymized_at`)',
    'SELECT 1'
);
PREPARE workspace_lifecycle_stmt FROM @workspace_lifecycle_sql;
EXECUTE workspace_lifecycle_stmt;
DEALLOCATE PREPARE workspace_lifecycle_stmt;
