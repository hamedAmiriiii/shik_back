-- حذف نرم محصول (از لیست پنهان می‌شود؛ سابقه خرید و purchased_products می‌ماند)
-- معادل migration: 2026_05_20_110000_add_soft_deletes_to_products_table

ALTER TABLE `products`
  ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;

-- بررسی:
-- SHOW COLUMNS FROM `products` LIKE 'deleted_at';
