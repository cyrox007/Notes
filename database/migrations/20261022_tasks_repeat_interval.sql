-- Настройка частоты повторов независимо от утренней сводки.
-- 0 — без повторов; 1 — ежедневно; 3 и 7 — раз в соответствующее число дней.
-- Значение 1 сохраняет поведение пользователей прежних версий.
ALTER TABLE `task_morning_digest_settings`
    ADD COLUMN `repeat_interval_days` TINYINT UNSIGNED NOT NULL DEFAULT 1;
