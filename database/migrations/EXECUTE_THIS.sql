-- =====================================================
-- SQL - فقط کوئری‌های اجرایی
-- =====================================================

-- قبل از ایجاد جدول جدید، اطمینان حاصل کنید جداول مرجع دارای ENGINE = InnoDB هستند.
ALTER TABLE `customers` ENGINE=InnoDB;
ALTER TABLE `states` ENGINE=InnoDB;
ALTER TABLE `cities` ENGINE=InnoDB;

-- 1️⃣ ایجاد جدول customer_addresses
CREATE TABLE IF NOT EXISTS `customer_addresses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NULL,
  `name` VARCHAR(255) NOT NULL,
  `last_name` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(11) NOT NULL,
  `address` LONGTEXT NOT NULL,
  `state_id` INT(4) NOT NULL,
  `state_name` VARCHAR(255) NULL,
  `city_id` INT(20) NOT NULL,
  `city_name` VARCHAR(255) NULL,
  `postal_code` VARCHAR(10) NOT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT `customer_addresses_customer_id_foreign` 
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_addresses_state_id_foreign` 
    FOREIGN KEY (`state_id`) REFERENCES `states` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_addresses_city_id_foreign` 
    FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE CASCADE,
  INDEX `customer_addresses_customer_id_index` (`customer_id`),
  INDEX `customer_addresses_is_default_index` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2️⃣ افزودن ستون address_id به جدول carts
ALTER TABLE `carts` 
ADD COLUMN `address_id` BIGINT UNSIGNED NULL AFTER `customer_id`;

-- 3️⃣ ایجاد Foreign Key برای address_id
ALTER TABLE `carts` 
ADD CONSTRAINT `carts_address_id_foreign` 
  FOREIGN KEY (`address_id`) REFERENCES `customer_addresses` (`id`) ON DELETE SET NULL;

-- 4️⃣ ایجاد Index برای address_id
ALTER TABLE `carts` 
ADD INDEX `carts_address_id_index` (`address_id`);
