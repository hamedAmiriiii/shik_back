-- شناسه یکتای کلاینت برای ثبت آفلاین مراجعه (هر فروشگاه)
-- معادل migration 2026_09_03_140000_add_client_id_to_oil_visits
-- چند ردیف با client_id خالی مجاز است؛ تکرار همان client_id در یک فروشگاه رد می‌شود.

SET NAMES utf8mb4;

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oil_visits' AND COLUMN_NAME = 'client_id'
);
SET @sql := IF(@has_col = 0,
  'ALTER TABLE `oil_visits` ADD COLUMN `client_id` VARCHAR(64) NULL DEFAULT NULL AFTER `created_by`, ADD UNIQUE KEY `oil_visits_atelier_client_id_unique` (`atelier_id`, `client_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
