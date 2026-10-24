-- Новые виды шаблонов и самостоятельные условия производства работ.
-- Существующие задания/бригады и тексты ранее выданных документов сохраняются.
ALTER TABLE `work_permit_templates`
  MODIFY COLUMN `kind` ENUM('job','crew','conditions','instructions') NOT NULL;
ALTER TABLE `work_permit_issuances`
  ADD COLUMN `work_conditions` TEXT NULL AFTER `special_instructions`;
