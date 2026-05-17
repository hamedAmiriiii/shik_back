-- =============================================================================
-- چندفروشگاهی: معادل دستی مایگریشن 2026_05_13_200000_add_shop_multitenancy_columns
-- برای MySQL / MariaDB — یک بار اجرا کنید (قبلش از دیتابیس بکاپ بگیرید).
--
-- اگر DROP INDEX خطای «Unknown key» داد، نام واقعی را ببینید:
--   SHOW INDEX FROM customers;   -- ستون Key_name (مثلاً phone یا customers_phone_unique)
--   SHOW INDEX FROM products;
--   SHOW INDEX FROM manufacturers;
--   SHOW INDEX FROM settings;
--   SHOW INDEX FROM user_shiksho;
--
-- اگر هیچ ردیفی در ateliers ندارید، @default_aid تهی می‌ماند؛ UPDATEها اثر ندارند
-- و ممکن است قبل از UNIQUE/FK روی customers خطا بگیرید — حداقل یک atelier بسازید.
--
-- خطای #1146 یعنی جدول ateliers در این دیتابیس نیست:
--   1) در phpMyAdmin مطمئن شوید همان دیتابیسی که در .env (DB_DATABASE) است را باز کرده‌اید.
--   2) ترجیحاً از ریشهٔ پروژه بزنید: php artisan migrate  (تا همهٔ جداول پایه ساخته شوند)
--   3) یا بلوک زیر را اجرا کنید تا حداقل جدول + یک ردیف پیش‌فرض ساخته شود.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 0) اگر جدول ateliers وجود ندارد (مثلاً #1146) — معادل مایگریشن create_ateliers_table
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ateliers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `code` VARCHAR(255) NOT NULL,
  `address` VARCHAR(255) NOT NULL,
  `business_license` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ateliers` (`name`, `code`, `address`, `business_license`, `created_at`, `updated_at`)
SELECT 'فروشگاه پیش‌فرض', 'DEFAULT', '-', '-', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `ateliers` LIMIT 1);

-- نخستین آتلیه به‌عنوان مقدار پیش‌فرض برای رکوردهای قدیمی
SET @default_aid = (SELECT MIN(id) FROM ateliers);

-- -----------------------------------------------------------------------------
-- 1) ateliers
-- -----------------------------------------------------------------------------
ALTER TABLE `ateliers`
  ADD COLUMN `shop_access_starts_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN `shop_access_ends_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN `shop_access_suspended` TINYINT(1) NOT NULL DEFAULT 0;

-- -----------------------------------------------------------------------------
-- 2) users
-- -----------------------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN `shop_staff_role` VARCHAR(32) NULL DEFAULT NULL AFTER `atelier_id`;

-- -----------------------------------------------------------------------------
-- 3) confirmation_codes
-- -----------------------------------------------------------------------------
ALTER TABLE `confirmation_codes`
  ADD COLUMN `atelier_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`,
  ADD INDEX `confirmation_codes_atelier_id_phone_index` (`atelier_id`, `phone`);

-- -----------------------------------------------------------------------------
-- 4) customers — ستون، پر کردن، حذف یکتا روی phone، یکتای ترکیبی، FK
-- -----------------------------------------------------------------------------
ALTER TABLE `customers`
  ADD COLUMN `atelier_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`;

UPDATE `customers` SET `atelier_id` = @default_aid WHERE `atelier_id` IS NULL AND @default_aid IS NOT NULL;

-- نام ایندکس ممکن است customers_phone_unique باشد؛ اگر خطا دادید نام را از SHOW INDEX بردارید
ALTER TABLE `customers` DROP INDEX `customers_phone_unique`;

ALTER TABLE `customers`
  ADD UNIQUE KEY `customers_atelier_id_phone_unique` (`atelier_id`, `phone`),
  ADD CONSTRAINT `customers_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL;

-- -----------------------------------------------------------------------------
-- 5) جداول مشترک: فقط ستون atelier_id + پر کردن
-- -----------------------------------------------------------------------------
ALTER TABLE `products` ADD COLUMN `atelier_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`;
ALTER TABLE `categories` ADD COLUMN `atelier_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`;
ALTER TABLE `manufacturers` ADD COLUMN `atelier_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`;
ALTER TABLE `purchases` ADD COLUMN `atelier_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`;
ALTER TABLE `carts` ADD COLUMN `atelier_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`;
ALTER TABLE `invoices` ADD COLUMN `atelier_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`;
ALTER TABLE `expenses` ADD COLUMN `atelier_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`;
ALTER TABLE `returned_products` ADD COLUMN `atelier_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`;
ALTER TABLE `shop_sms_logs` ADD COLUMN `atelier_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`;

UPDATE `products` SET `atelier_id` = @default_aid WHERE `atelier_id` IS NULL AND @default_aid IS NOT NULL;
UPDATE `categories` SET `atelier_id` = @default_aid WHERE `atelier_id` IS NULL AND @default_aid IS NOT NULL;
UPDATE `manufacturers` SET `atelier_id` = @default_aid WHERE `atelier_id` IS NULL AND @default_aid IS NOT NULL;
UPDATE `purchases` SET `atelier_id` = @default_aid WHERE `atelier_id` IS NULL AND @default_aid IS NOT NULL;
UPDATE `carts` SET `atelier_id` = @default_aid WHERE `atelier_id` IS NULL AND @default_aid IS NOT NULL;
UPDATE `invoices` SET `atelier_id` = @default_aid WHERE `atelier_id` IS NULL AND @default_aid IS NOT NULL;
UPDATE `expenses` SET `atelier_id` = @default_aid WHERE `atelier_id` IS NULL AND @default_aid IS NOT NULL;
UPDATE `returned_products` SET `atelier_id` = @default_aid WHERE `atelier_id` IS NULL AND @default_aid IS NOT NULL;
UPDATE `shop_sms_logs` SET `atelier_id` = @default_aid WHERE `atelier_id` IS NULL AND @default_aid IS NOT NULL;

-- -----------------------------------------------------------------------------
-- 6) products — بارکد یکتا per فروشگاه
-- -----------------------------------------------------------------------------
ALTER TABLE `products` DROP INDEX `products_barcode_unique`;

ALTER TABLE `products`
  ADD UNIQUE KEY `products_atelier_id_barcode_unique` (`atelier_id`, `barcode`),
  ADD CONSTRAINT `products_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL;

-- -----------------------------------------------------------------------------
-- 7) manufacturers — نام یکتا per فروشگاه
-- -----------------------------------------------------------------------------
ALTER TABLE `manufacturers` DROP INDEX `manufacturers_name_unique`;

ALTER TABLE `manufacturers`
  ADD UNIQUE KEY `manufacturers_atelier_id_name_unique` (`atelier_id`, `name`),
  ADD CONSTRAINT `manufacturers_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL;

-- -----------------------------------------------------------------------------
-- 8) FK برای بقیه جداولی که فقط ستون گرفتند
-- -----------------------------------------------------------------------------
ALTER TABLE `categories`
  ADD CONSTRAINT `categories_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL;

ALTER TABLE `purchases`
  ADD CONSTRAINT `purchases_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL;

ALTER TABLE `carts`
  ADD CONSTRAINT `carts_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL;

ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL;

ALTER TABLE `expenses`
  ADD CONSTRAINT `expenses_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL;

ALTER TABLE `returned_products`
  ADD CONSTRAINT `returned_products_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL;

ALTER TABLE `shop_sms_logs`
  ADD CONSTRAINT `shop_sms_logs_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL;

-- -----------------------------------------------------------------------------
-- 9) settings
-- -----------------------------------------------------------------------------
ALTER TABLE `settings`
  ADD COLUMN `atelier_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`;

UPDATE `settings` SET `atelier_id` = @default_aid WHERE `atelier_id` IS NULL AND @default_aid IS NOT NULL;

ALTER TABLE `settings` DROP INDEX `settings_key_unique`;

ALTER TABLE `settings`
  ADD UNIQUE KEY `settings_atelier_id_key_unique` (`atelier_id`, `key`),
  ADD CONSTRAINT `settings_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL;

-- -----------------------------------------------------------------------------
-- 10) user_shiksho
-- -----------------------------------------------------------------------------
ALTER TABLE `user_shiksho`
  ADD COLUMN `atelier_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`;

UPDATE `user_shiksho` SET `atelier_id` = @default_aid WHERE `atelier_id` IS NULL AND @default_aid IS NOT NULL;

ALTER TABLE `user_shiksho` DROP INDEX `user_shiksho_phone_unique`;

ALTER TABLE `user_shiksho`
  ADD UNIQUE KEY `user_shiksho_atelier_id_phone_unique` (`atelier_id`, `phone`),
  ADD CONSTRAINT `user_shiksho_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- پایان. اگر از artisan migrate هم استفاده می‌کنید، این مایگریشن را دوباره اجرا نکنید.
-- برای ثبت دستی در جدول migrations (batch را با آخرین batch+1 عوض کنید):
-- INSERT INTO migrations (`migration`, `batch`) VALUES ('2026_05_13_200000_add_shop_multitenancy_columns', 1);
-- =============================================================================
