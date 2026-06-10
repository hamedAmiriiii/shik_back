-- بازه‌های درصد اعتبار وفاداری — حداکثر ۵ بازه برای هر فروشگاه
CREATE TABLE IF NOT EXISTS `shop_loyalty_credit_tiers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `sort_order` TINYINT UNSIGNED NOT NULL,
  `max_amount` DECIMAL(15, 2) NULL,
  `percent` DECIMAL(5, 2) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shop_loyalty_credit_tiers_atelier_sort_unique` (`atelier_id`, `sort_order`),
  KEY `shop_loyalty_credit_tiers_atelier_id_foreign` (`atelier_id`),
  CONSTRAINT `shop_loyalty_credit_tiers_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- پیش‌فرض برای هر فروشگاه موجود (در صورت نبود رکورد)
INSERT INTO `shop_loyalty_credit_tiers` (`atelier_id`, `sort_order`, `max_amount`, `percent`, `created_at`, `updated_at`)
SELECT a.`id`, 1, 1000000.00, 3.00, NOW(), NOW() FROM `ateliers` a
WHERE NOT EXISTS (SELECT 1 FROM `shop_loyalty_credit_tiers` t WHERE t.`atelier_id` = a.`id` AND t.`sort_order` = 1);
INSERT INTO `shop_loyalty_credit_tiers` (`atelier_id`, `sort_order`, `max_amount`, `percent`, `created_at`, `updated_at`)
SELECT a.`id`, 2, 2000000.00, 4.00, NOW(), NOW() FROM `ateliers` a
WHERE NOT EXISTS (SELECT 1 FROM `shop_loyalty_credit_tiers` t WHERE t.`atelier_id` = a.`id` AND t.`sort_order` = 2);
INSERT INTO `shop_loyalty_credit_tiers` (`atelier_id`, `sort_order`, `max_amount`, `percent`, `created_at`, `updated_at`)
SELECT a.`id`, 3, NULL, 5.00, NOW(), NOW() FROM `ateliers` a
WHERE NOT EXISTS (SELECT 1 FROM `shop_loyalty_credit_tiers` t WHERE t.`atelier_id` = a.`id` AND t.`sort_order` = 3);
  