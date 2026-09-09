-- سفارش حضوری برای یک فروشگاه (atelier_id را عوض کنید)
-- اگر INSERT خطای Duplicate entry برای `key` داد، اول این را اجرا کنید:
--   atelier/database/sql/fix_settings_multitenancy_index_manual.sql

INSERT INTO `settings` (`key`, `value`, `atelier_id`, `created_at`, `updated_at`)
VALUES ('restaurant_cafe_enabled', '1', 28, NOW(), NOW())
ON DUPLICATE KEY UPDATE `value` = '1', `updated_at` = NOW();

SELECT id, `key`, `value`, atelier_id
FROM `settings`
WHERE `key` = 'restaurant_cafe_enabled'
ORDER BY atelier_id;
