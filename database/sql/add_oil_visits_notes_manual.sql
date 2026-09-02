-- توضیحات اختیاری روی ثبت تعویض روغن
-- معادل migration 2026_09_01_190000_add_notes_to_oil_visits

SET NAMES utf8mb4;

SET @has_notes := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oil_visits' AND COLUMN_NAME = 'notes'
);
SET @sql := IF(@has_notes = 0,
  'ALTER TABLE `oil_visits` ADD COLUMN `notes` TEXT NULL AFTER `next_km`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
