-- Рабочее место каждого допуска хранится неизменяемым историческим снимком.
-- Для уже зарегистрированных допусков значение остаётся неизвестным.
ALTER TABLE work_permit_work_periods
  ADD COLUMN workplace VARCHAR(500) NULL AFTER started_at;
