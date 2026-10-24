-- Часовой пояс организации и неизменяемый снимок для выданного наряда.
-- UTC остаётся значением для существующих организаций и документов.
ALTER TABLE `work_permit_organizations`
 ADD COLUMN `time_zone` VARCHAR(64) NOT NULL DEFAULT 'UTC' AFTER `name`;
ALTER TABLE `work_permit_issuances`
 ADD COLUMN `time_zone` VARCHAR(64) NOT NULL DEFAULT 'UTC' AFTER `planned_finish`;
