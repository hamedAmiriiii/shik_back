-- فعال‌سازی پیش‌فرض باشگاه مشتریان برای فروشگاه‌های 1، 5، 13، 17
-- بقیه فروشگاه‌ها در صورت نبود رکورد خاموش می‌مانند (پیش‌فرض کد).

INSERT INTO `settings` (`key`, `value`, `atelier_id`, `created_at`, `updated_at`)
SELECT 'customer_club_enabled', '1', a.id, NOW(), NOW()
FROM `ateliers` a
WHERE a.id IN (1, 5, 13, 17)
  AND NOT EXISTS (
    SELECT 1 FROM `settings` s
    WHERE s.`key` = 'customer_club_enabled' AND s.`atelier_id` = a.id
  );
