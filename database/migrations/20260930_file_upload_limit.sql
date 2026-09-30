INSERT INTO `system_settings`
    (`setting_key`,`setting_value`,`setting_type`,`category`,`description`,`is_editable`)
VALUES
    ('file_manager_max_upload_bytes','10485760','integer','file_manager','Максимальный размер одного загружаемого файла в байтах',1)
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);
