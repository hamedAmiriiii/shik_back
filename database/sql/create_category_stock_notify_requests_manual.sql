-- لیست «موجود شد خبر بده» برای دسته‌بندی (حالت منو)
-- در صورت وجود جدول، خطا ندهید (دستی چک کنید یا IF NOT EXISTS در MySQL 8+)

CREATE TABLE IF NOT EXISTS `category_stock_notify_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `atelier_id` bigint unsigned NOT NULL,
  `category_id` bigint unsigned NOT NULL,
  `phone` varchar(11) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_stock_notify_unique` (`atelier_id`, `category_id`, `phone`),
  KEY `category_stock_notify_cat_idx` (`atelier_id`, `category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
