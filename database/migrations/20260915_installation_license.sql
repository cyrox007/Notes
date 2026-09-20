-- 1.0 installation-wide licensing foundation.
-- The installation identifier is generated once and must remain stable across
-- application updates. The signed license token is stored separately and can be
-- replaced/cleared without touching user data.

INSERT INTO `system_settings`
    (`setting_key`,`setting_value`,`setting_type`,`category`,`description`,`is_editable`)
VALUES
    ('installation_id',LOWER(UUID()),'string','licensing','Stable installation identifier used to bind signed licenses',0)
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

INSERT INTO `system_settings`
    (`setting_key`,`setting_value`,`setting_type`,`category`,`description`,`is_editable`)
VALUES
    ('workspace_license_token','','string','licensing','Signed installation-wide Workspace Organizer license token',0)
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);
