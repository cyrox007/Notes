-- Обновление колонки type в таблице user_files
-- Изменяем ENUM на VARCHAR для поддержки различных типов файлов

ALTER TABLE `user_files` 
MODIFY COLUMN `type` VARCHAR(50) NOT NULL DEFAULT 'file' 
COMMENT 'Тип: folder, file, document, image, video, audio, archive, code';
