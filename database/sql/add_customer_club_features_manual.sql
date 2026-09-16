-- باشگاه مشتریان: تاریخ تولد، گروه‌های پیامک، اعلان موجود شدن کالا
-- معادل migrations:
--   2026_09_16_150000_add_birth_date_to_user_shiksho
--   2026_09_16_150100_create_shop_customer_groups_tables
--   2026_09_16_150200_create_product_stock_notify_requests_table

ALTER TABLE `user_shiksho`
    ADD COLUMN `birth_date` DATE NULL AFTER `name`;

CREATE TABLE IF NOT EXISTS `shop_customer_groups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shop_customer_groups_atelier_name_idx` (`atelier_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_customer_group_members` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` BIGINT UNSIGNED NOT NULL,
  `phone` VARCHAR(11) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shop_customer_group_members_group_phone_unique` (`group_id`, `phone`),
  CONSTRAINT `shop_customer_group_members_group_id_foreign`
    FOREIGN KEY (`group_id`) REFERENCES `shop_customer_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_stock_notify_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `phone` VARCHAR(11) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_stock_notify_unique` (`atelier_id`, `product_id`, `phone`),
  KEY `product_stock_notify_product_idx` (`atelier_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
