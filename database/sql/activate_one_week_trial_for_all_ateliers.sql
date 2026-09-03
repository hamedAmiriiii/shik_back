-- فعال‌سازی نسخه آزمایشی یک‌هفته‌ای برای همه فروشگاه‌ها (فروشگاه + تعویض روغن)
-- معادل migration 2026_09_03_100000_activate_one_week_trial_for_all_ateliers
-- فروشگاه‌هایی که اعتبار طولانی‌تر دارند کوتاه نمی‌شوند.

SET NAMES utf8mb4;

UPDATE `ateliers`
SET
  `shop_access_suspended` = 0,
  `shop_access_starts_at` = CASE
    WHEN `shop_access_starts_at` IS NULL OR `shop_access_starts_at` > NOW() THEN NOW()
    ELSE `shop_access_starts_at`
  END,
  `shop_access_ends_at` = CASE
    WHEN `shop_access_ends_at` IS NULL OR `shop_access_ends_at` < DATE_ADD(NOW(), INTERVAL 7 DAY)
      THEN DATE_ADD(NOW(), INTERVAL 7 DAY)
    ELSE `shop_access_ends_at`
  END;
