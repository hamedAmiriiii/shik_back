-- سامانهٔ درخواست تعمیرکار (معادل migration 2026_10_01_100000_create_repair_tables)

CREATE TABLE IF NOT EXISTS `repair_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `role` varchar(20) NOT NULL DEFAULT 'customer',
  `name` varchar(255) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `specialty` varchar(255) DEFAULT NULL,
  `labor_share_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `card_number` varchar(32) DEFAULT NULL,
  `address` varchar(1000) DEFAULT NULL,
  `notes` varchar(2000) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `repair_users_phone_unique` (`phone`),
  KEY `repair_users_role_is_active_index` (`role`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `repair_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned NOT NULL,
  `technician_id` bigint unsigned DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `address` varchar(1000) NOT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(20) NOT NULL,
  `preferred_time` varchar(255) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `admin_note` varchar(2000) DEFAULT NULL,
  `labor_amount` bigint unsigned NOT NULL DEFAULT '0',
  `parts_amount` bigint unsigned NOT NULL DEFAULT '0',
  `total_amount` bigint unsigned NOT NULL DEFAULT '0',
  `cost_description` varchar(2000) DEFAULT NULL,
  `share_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `technician_share` bigint unsigned NOT NULL DEFAULT '0',
  `platform_share` bigint unsigned NOT NULL DEFAULT '0',
  `payment_method` varchar(20) DEFAULT NULL,
  `payment_ref` varchar(255) DEFAULT NULL,
  `gateway_payment_id` bigint unsigned DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `receipt_submitted_at` timestamp NULL DEFAULT NULL,
  `receipt_reject_reason` varchar(1000) DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `invoiced_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `canceled_at` timestamp NULL DEFAULT NULL,
  `cancel_reason` varchar(1000) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `repair_requests_status_created_at_index` (`status`,`created_at`),
  KEY `repair_requests_customer_id_created_at_index` (`customer_id`,`created_at`),
  KEY `repair_requests_technician_id_status_index` (`technician_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `repair_payouts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `technician_id` bigint unsigned NOT NULL,
  `amount` bigint unsigned NOT NULL,
  `paid_on` date NOT NULL,
  `method` varchar(50) DEFAULT NULL,
  `note` varchar(1000) DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `repair_payouts_technician_id_paid_on_index` (`technician_id`,`paid_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `repair_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `value` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `repair_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
