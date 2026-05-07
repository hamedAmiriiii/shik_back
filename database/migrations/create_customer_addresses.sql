-- =====================================================
-- SQL Queries for Customer Addresses System
-- Database: MySQL
-- Created: 2026-04-29
-- =====================================================

-- =====================================================
-- 1. ایجاد جدول customer_addresses
-- =====================================================
CREATE TABLE `customer_addresses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `last_name` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(11) NOT NULL,
  `address` LONGTEXT NOT NULL,
  `state_id` BIGINT UNSIGNED NOT NULL,
  `state_name` VARCHAR(255) NULL,
  `city_id` BIGINT UNSIGNED NOT NULL,
  `city_name` VARCHAR(255) NULL,
  `postal_code` VARCHAR(10) NOT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  
  -- Foreign Keys
  CONSTRAINT `customer_addresses_customer_id_foreign` 
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_addresses_state_id_foreign` 
    FOREIGN KEY (`state_id`) REFERENCES `states` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_addresses_city_id_foreign` 
    FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE CASCADE,
  
  -- Indexes
  INDEX `customer_addresses_customer_id_index` (`customer_id`),
  INDEX `customer_addresses_is_default_index` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. افزودن ستون address_id به جدول carts
-- =====================================================
ALTER TABLE `carts` 
ADD COLUMN `address_id` BIGINT UNSIGNED NULL 
AFTER `customer_id`;

-- ایجاد Foreign Key برای address_id
ALTER TABLE `carts` 
ADD CONSTRAINT `carts_address_id_foreign` 
  FOREIGN KEY (`address_id`) REFERENCES `customer_addresses` (`id`) ON DELETE SET NULL;

-- ایجاد Index برای address_id
ALTER TABLE `carts` 
ADD INDEX `carts_address_id_index` (`address_id`);

-- =====================================================
-- 3. بررسی جداول
-- =====================================================
-- برای بررسی ساختار جدول customer_addresses
-- DESCRIBE customer_addresses;

-- برای بررسی ستون‌های جدول carts (باید address_id را ببینید)
-- DESCRIBE carts;

-- =====================================================
-- 4. کوئری‌های مفید برای تست و بررسی
-- =====================================================

-- تعداد آدرس‌های ثبت‌شده برای هر کاربر
-- SELECT customer_id, COUNT(*) as total_addresses 
-- FROM customer_addresses 
-- GROUP BY customer_id;

-- لیست آدرس‌های پیش‌فرض
-- SELECT * FROM customer_addresses WHERE is_default = 1;

-- سبدهای خریدی که آدرس انتخاب کرده‌اند
-- SELECT c.id, c.customer_id, c.address_id, ca.name, ca.phone 
-- FROM carts c
-- LEFT JOIN customer_addresses ca ON c.address_id = ca.id;
