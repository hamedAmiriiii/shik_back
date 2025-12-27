-- SQL برای ایجاد جدول customers

CREATE TABLE IF NOT EXISTS `customers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(11) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `name` VARCHAR(255) NULL,
  `last_name` VARCHAR(255) NULL,
  `national_code` VARCHAR(10) NULL,
  `state_id` BIGINT UNSIGNED NULL,
  `city_id` BIGINT UNSIGNED NULL,
  `address` TEXT NULL,
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customers_phone_unique` (`phone`),
  KEY `customers_phone_index` (`phone`),
  KEY `customers_national_code_index` (`national_code`),
  KEY `customers_state_id_index` (`state_id`),
  KEY `customers_city_id_index` (`city_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- اضافه کردن Foreign Key constraints به صورت جداگانه (در صورت نیاز)
-- اگر جداول states و cities وجود دارند و type id آنها BIGINT UNSIGNED است:

-- ALTER TABLE `customers` 
--   ADD CONSTRAINT `customers_state_id_foreign` 
--     FOREIGN KEY (`state_id`) REFERENCES `states` (`id`) 
--     ON DELETE SET NULL,
--   ADD CONSTRAINT `customers_city_id_foreign` 
--     FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) 
--     ON DELETE SET NULL;

