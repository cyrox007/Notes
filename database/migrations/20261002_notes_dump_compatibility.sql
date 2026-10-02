-- Совместимость дампов Notes со старыми phpMyAdmin.
-- Некоторые версии phpMyAdmin при экспорте MySQL 8.x теряют имя последней
-- колонки descending-индекса и пишут, например:
--   ADD KEY idx_updated_notes (user_id, DESC)
-- Такой дамп невозможно импортировать: MySQL воспринимает DESC как имя столбца.
--
-- Для используемых запросов направление индекса не обязательно: InnoDB умеет
-- сканировать обычный B-tree в обратном порядке для ORDER BY ... DESC.
-- Поэтому опубликованные установки 1.0.14 переводим на обычные ASC-индексы.

SET @notes_dump_has_idx_user_notes = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'notes'
      AND index_name = 'idx_user_notes'
);
SET @notes_dump_drop_idx_user_notes = IF(
    @notes_dump_has_idx_user_notes > 0,
    'ALTER TABLE notes DROP INDEX idx_user_notes',
    'SELECT 1'
);
PREPARE notes_dump_drop_idx_user_notes_stmt FROM @notes_dump_drop_idx_user_notes;
EXECUTE notes_dump_drop_idx_user_notes_stmt;
DEALLOCATE PREPARE notes_dump_drop_idx_user_notes_stmt;

SET @notes_dump_has_idx_updated_notes = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'notes'
      AND index_name = 'idx_updated_notes'
);
SET @notes_dump_drop_idx_updated_notes = IF(
    @notes_dump_has_idx_updated_notes > 0,
    'ALTER TABLE notes DROP INDEX idx_updated_notes',
    'SELECT 1'
);
PREPARE notes_dump_drop_idx_updated_notes_stmt FROM @notes_dump_drop_idx_updated_notes;
EXECUTE notes_dump_drop_idx_updated_notes_stmt;
DEALLOCATE PREPARE notes_dump_drop_idx_updated_notes_stmt;

SET @notes_dump_has_idx_profile_public = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'notes'
      AND index_name = 'idx_notes_profile_public'
);
SET @notes_dump_drop_idx_profile_public = IF(
    @notes_dump_has_idx_profile_public > 0,
    'ALTER TABLE notes DROP INDEX idx_notes_profile_public',
    'SELECT 1'
);
PREPARE notes_dump_drop_idx_profile_public_stmt FROM @notes_dump_drop_idx_profile_public;
EXECUTE notes_dump_drop_idx_profile_public_stmt;
DEALLOCATE PREPARE notes_dump_drop_idx_profile_public_stmt;

ALTER TABLE `notes`
    ADD INDEX `idx_user_notes` (`user_id`, `is_deleted`, `created_note`),
    ADD INDEX `idx_updated_notes` (`user_id`, `updated_note`),
    ADD INDEX `idx_notes_profile_public` (`user_id`, `is_profile_public`, `is_deleted`, `updated_note`);
