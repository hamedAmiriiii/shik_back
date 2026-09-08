-- درگاه زرین‌پال: پلن اکانت + جدول پرداخت‌ها

CREATE TABLE IF NOT EXISTS `shop_plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `duration_days` int unsigned NOT NULL,
  `price_rial` bigint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` tinyint unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gateway_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `atelier_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `type` varchar(32) NOT NULL,
  `item_id` bigint unsigned NOT NULL,
  `amount_rial` bigint unsigned NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `authority` varchar(64) DEFAULT NULL,
  `ref_id` varchar(64) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `gateway` varchar(32) NOT NULL DEFAULT 'zarinpal',
  `return_url` text DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gateway_payments_authority_unique` (`authority`),
  KEY `gateway_payments_atelier_status` (`atelier_id`, `status`),
  KEY `gateway_payments_type_item` (`type`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `shop_plans` (`name`, `duration_days`, `price_rial`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'اکانت یک‌ماهه', 30, 5000000, 1, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `shop_plans` WHERE `name` = 'اکانت یک‌ماهه');

INSERT INTO `shop_plans` (`name`, `duration_days`, `price_rial`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'اکانت سه‌ماهه', 90, 12000000, 1, 2, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `shop_plans` WHERE `name` = 'اکانت سه‌ماهه');

INSERT INTO `shop_plans` (`name`, `duration_days`, `price_rial`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'اکانت یک‌ساله', 365, 40000000, 1, 3, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `shop_plans` WHERE `name` = 'اکانت یک‌ساله');
