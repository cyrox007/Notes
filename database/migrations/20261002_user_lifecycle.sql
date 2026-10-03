-- Жизненный цикл пользовательского аккаунта.
-- Физическое удаление строки users опасно: на неё ссылаются сообщения,
-- общие задачи и другие совместные объекты. Поэтому после периода хранения
-- персональные данные обезличиваются, а технический tombstone остаётся.
--
-- Миграция допускает уже частично синхронизированную схему: если нужные поля
-- или индекс уже существуют в совместимом виде, они не создаются повторно.
-- Несовместимое состояние отклоняется до изменения таблицы.

DELIMITER //
CREATE PROCEDURE `workspace_reconcile_user_lifecycle`()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'users'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Не найдена таблица users для миграции жизненного цикла';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'account_status'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'В users отсутствует обязательное поле account_status';
    END IF;

    IF EXISTS (
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
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Несовместимое поле users.deletion_requested_at';
    END IF;

    IF EXISTS (
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
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Несовместимое поле users.purge_after';
    END IF;

    IF EXISTS (
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
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Несовместимое поле users.anonymized_at';
    END IF;

    IF EXISTS (
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
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Несовместимый индекс users.idx_users_purge';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'deletion_requested_at'
    ) THEN
        ALTER TABLE `users`
            ADD COLUMN `deletion_requested_at` DATETIME NULL AFTER `account_status`;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'purge_after'
    ) THEN
        ALTER TABLE `users`
            ADD COLUMN `purge_after` DATETIME NULL AFTER `deletion_requested_at`;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'anonymized_at'
    ) THEN
        ALTER TABLE `users`
            ADD COLUMN `anonymized_at` DATETIME NULL AFTER `purge_after`;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND index_name = 'idx_users_purge'
    ) THEN
        ALTER TABLE `users`
            ADD KEY `idx_users_purge` (`purge_after`, `anonymized_at`);
    END IF;
END//

CALL `workspace_reconcile_user_lifecycle`()//
DROP PROCEDURE `workspace_reconcile_user_lifecycle`//
DELIMITER ;
