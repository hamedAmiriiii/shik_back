-- برندهای دستگاه تعمیرات (معادل migration 2026_11_03_120000_create_repair_brands_table)

CREATE TABLE IF NOT EXISTS `repair_brands` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `repair_brands` (`name`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT v.name, 1, v.sort_order, NOW(), NOW()
FROM (
  SELECT 'سامسونگ' AS name, 0 AS sort_order
  UNION ALL SELECT 'ال‌جی', 1
  UNION ALL SELECT 'بوش', 2
  UNION ALL SELECT 'دوو', 3
  UNION ALL SELECT 'پاکشوما', 4
  UNION ALL SELECT 'اسنوا', 5
  UNION ALL SELECT 'امرسان', 6
  UNION ALL SELECT 'هیمالیا', 7
  UNION ALL SELECT 'آبسال', 8
  UNION ALL SELECT 'حایر', 9
  UNION ALL SELECT 'کنوود', 10
  UNION ALL SELECT 'فیلور', 11
  UNION ALL SELECT 'زیرووات', 12
  UNION ALL SELECT 'مابه', 13
  UNION ALL SELECT 'سایر', 14
) v
WHERE NOT EXISTS (SELECT 1 FROM `repair_brands`);

ALTER TABLE `repair_requests`
  ADD COLUMN `brand_id` bigint unsigned DEFAULT NULL AFTER `service_id`,
  ADD COLUMN `brand` varchar(255) DEFAULT NULL AFTER `brand_id`,
  ADD KEY `repair_requests_brand_id_index` (`brand_id`);
