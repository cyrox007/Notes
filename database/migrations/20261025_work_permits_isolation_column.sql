-- Самостоятельная третья графа приложения №7: изоляция и ограждение.
-- Старые записи остаются без вымышленных мероприятий.
ALTER TABLE work_permit_safety_measures
  ADD COLUMN isolated_parts VARCHAR(1000) NULL AFTER isolation;
