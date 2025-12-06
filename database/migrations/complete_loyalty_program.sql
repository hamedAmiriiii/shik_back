-- SQL کامل برای باشگاه مشتریان شیک شو
-- این فایل شامل تمام جداول و فیلدهای مورد نیاز است

-- 1. ایجاد جدول customer_phones
CREATE TABLE IF NOT EXISTS `customer_phones` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. ایجاد جدول user_shiksho (باشگاه مشتریان)
CREATE TABLE IF NOT EXISTS `user_shiksho` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(11) NOT NULL,
  `credit` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_shiksho_phone_unique` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. اضافه کردن فیلدهای باشگاه مشتریان به جدول purchased_products
-- توجه: اگر فیلدها قبلاً وجود دارند، این دستور خطا می‌دهد
-- در این صورت می‌توانید خطوط مربوط به فیلدهای موجود را حذف کنید

ALTER TABLE `purchased_products` 
  ADD COLUMN IF NOT EXISTS `phone` VARCHAR(11) NULL AFTER `purchase_price`,
  ADD COLUMN IF NOT EXISTS `total_amount` DECIMAL(15, 2) NULL AFTER `phone`,
  ADD COLUMN IF NOT EXISTS `credit_used` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 AFTER `total_amount`,
  ADD COLUMN IF NOT EXISTS `credit_earned` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 AFTER `credit_used`;

-- اگر MySQL شما از IF NOT EXISTS پشتیبانی نمی‌کند، از این دستورات استفاده کنید:
-- ALTER TABLE `purchased_products` 
--   ADD COLUMN `phone` VARCHAR(11) NULL AFTER `purchase_price`,
--   ADD COLUMN `total_amount` DECIMAL(15, 2) NULL AFTER `phone`,
--   ADD COLUMN `credit_used` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 AFTER `total_amount`,
--   ADD COLUMN `credit_earned` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 AFTER `credit_used`;

