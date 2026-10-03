-- نوع خدمات، خدمات هر تعمیرکار و تأیید ثبت‌نام تعمیرکار
-- (معادل migration 2026_10_03_110000_create_repair_services_and_technician_approval)

CREATE TABLE IF NOT EXISTS `repair_services` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `repair_services` (`name`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT v.name, 1, v.sort_order, NOW(), NOW()
FROM (
  SELECT 'لوازم خانگی' AS name, 0 AS sort_order
  UNION ALL SELECT 'تأسیسات و لوله‌کشی', 1
  UNION ALL SELECT 'برق ساختمان', 2
  UNION ALL SELECT 'کولر و پکیج', 3
  UNION ALL SELECT 'سایر', 4
) v
WHERE NOT EXISTS (SELECT 1 FROM `repair_services`);

CREATE TABLE IF NOT EXISTS `repair_technician_services` (
  `technician_id` bigint unsigned NOT NULL,
  `service_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`technician_id`, `service_id`),
  KEY `repair_technician_services_service_id_index` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `repair_users`
  ADD COLUMN `approval_status` varchar(20) NOT NULL DEFAULT 'approved' AFTER `role`,
  ADD COLUMN `approval_note` varchar(1000) DEFAULT NULL AFTER `approval_status`;

ALTER TABLE `repair_requests`
  ADD COLUMN `service_id` bigint unsigned DEFAULT NULL AFTER `technician_id`,
  ADD KEY `repair_requests_service_id_index` (`service_id`);
