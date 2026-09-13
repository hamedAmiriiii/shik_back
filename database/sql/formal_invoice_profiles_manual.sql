-- جداول اطلاعات فاکتور رسمی (جدا از جداول اصلی فروش)
-- فقط وقتی کاربر اطلاعات را وارد کند ردیف ساخته می‌شود.

CREATE TABLE IF NOT EXISTS `formal_invoice_seller_profiles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `legal_name` VARCHAR(191) NULL,
  `brand_name` VARCHAR(191) NULL,
  `province` VARCHAR(120) NULL,
  `city` VARCHAR(120) NULL,
  `address` VARCHAR(500) NULL,
  `postal_code` VARCHAR(20) NULL,
  `phone` VARCHAR(40) NULL,
  `economic_code` VARCHAR(64) NULL,
  `national_id` VARCHAR(64) NULL,
  `registration_number` VARCHAR(64) NULL,
  `fixed_notes` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `formal_invoice_seller_profiles_atelier_id_unique` (`atelier_id`),
  CONSTRAINT `formal_invoice_seller_profiles_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `formal_invoice_buyer_profiles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `full_name` VARCHAR(191) NULL,
  `province` VARCHAR(120) NULL,
  `city` VARCHAR(120) NULL,
  `address` VARCHAR(500) NULL,
  `postal_code` VARCHAR(20) NULL,
  `economic_code` VARCHAR(64) NULL,
  `national_id` VARCHAR(64) NULL,
  `registration_number` VARCHAR(64) NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `formal_invoice_buyer_profiles_atelier_phone_unique` (`atelier_id`, `phone`),
  CONSTRAINT `formal_invoice_buyer_profiles_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
