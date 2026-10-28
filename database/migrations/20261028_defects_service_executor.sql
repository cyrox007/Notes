-- Назначенный исполнитель хранится отдельно для каждой службы дефекта.
-- Пустое значение означает, что служба указана, но исполнитель ещё не назначен.
ALTER TABLE `defect_responsible_services`
    ADD COLUMN `executor_name` VARCHAR(160) DEFAULT NULL;
