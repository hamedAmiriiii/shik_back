-- SQL کامل برای تغییر ساختار به سبد خرید (Purchase)
-- این فایل شامل تمام جداول و فیلدهای مورد نیاز است

-- 1. ایجاد جدول purchases (سبد خرید)
CREATE TABLE IF NOT EXISTS `purchases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(11) NULL,
  `total_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
  `credit_used` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
  `credit_earned` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. اضافه کردن purchase_id به جدول purchased_products
-- توجه: اگر فیلد قبلاً وجود دارد، این دستور خطا می‌دهد
ALTER TABLE `purchased_products` 
  ADD COLUMN `purchase_id` BIGINT UNSIGNED NULL AFTER `id`,
  ADD CONSTRAINT `purchased_products_purchase_id_foreign` 
    FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) 
    ON DELETE CASCADE;

-- 3. ایجاد ایندکس برای purchase_id (اختیاری اما توصیه می‌شود)
CREATE INDEX `purchased_products_purchase_id_index` ON `purchased_products` (`purchase_id`);

-- اگر MySQL شما از IF NOT EXISTS در ALTER TABLE پشتیبانی نمی‌کند، 
-- ابتدا بررسی کنید که فیلد وجود ندارد:
-- SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
-- WHERE TABLE_SCHEMA = 'your_database_name' 
-- AND TABLE_NAME = 'purchased_products' 
-- AND COLUMN_NAME = 'purchase_id';

