-- درخواست مشاوره / خرید منوی دیجیتال وبینو
CREATE TABLE IF NOT EXISTS `consultation_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `state_id` bigint unsigned DEFAULT NULL,
  `city_id` bigint unsigned DEFAULT NULL,
  `state_name` varchar(255) DEFAULT NULL,
  `city_name` varchar(255) DEFAULT NULL,
  `business_name` varchar(255) NOT NULL,
  `source` varchar(64) NOT NULL DEFAULT 'digital_menu',
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `admin_note` text,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `consultation_requests_phone_index` (`phone`),
  KEY `consultation_requests_status_created_at_index` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
