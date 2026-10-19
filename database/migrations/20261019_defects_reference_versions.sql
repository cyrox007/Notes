-- Версии позволяют безопасно редактировать и архивировать справочники.
-- Обновление существующих данных сохраняет первоначальную версию 1.
ALTER TABLE `defect_areas` ADD COLUMN `version` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `defect_object_categories` ADD COLUMN `version` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `defect_objects` ADD COLUMN `version` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `defect_services` ADD COLUMN `version` BIGINT UNSIGNED NOT NULL DEFAULT 1;
