-- Жизненный цикл пользовательского аккаунта.
-- Физическое удаление строки users опасно: на неё ссылаются сообщения,
-- общие задачи и другие совместные объекты. Поэтому после периода хранения
-- персональные данные обезличиваются, а технический tombstone остаётся.

ALTER TABLE `users`
    ADD COLUMN `deletion_requested_at` DATETIME NULL AFTER `account_status`,
    ADD COLUMN `purge_after` DATETIME NULL AFTER `deletion_requested_at`,
    ADD COLUMN `anonymized_at` DATETIME NULL AFTER `purge_after`,
    ADD KEY `idx_users_purge` (`purge_after`, `anonymized_at`);
