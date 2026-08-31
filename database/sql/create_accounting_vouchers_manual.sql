-- سند حسابداری دوطرفه + آرتیکل‌ها
-- بعد از create_accounting_accounts_manual.sql اجرا شود.

CREATE TABLE IF NOT EXISTS `accounting_vouchers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `atelier_id` bigint unsigned NOT NULL,
  `number` int unsigned NOT NULL,
  `date` date NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `source_type` varchar(64) NOT NULL,
  `source_id` bigint unsigned NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'posted',
  `reverses_voucher_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `active_source_key` varchar(96) DEFAULT NULL COMMENT 'فقط سند posted غیربرگشتی: source_type:source_id',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accounting_vouchers_atelier_number_unique` (`atelier_id`, `number`),
  UNIQUE KEY `accounting_vouchers_active_source_unique` (`atelier_id`, `active_source_key`),
  KEY `accounting_vouchers_atelier_id_date_index` (`atelier_id`, `date`),
  KEY `accounting_vouchers_source_index` (`atelier_id`, `source_type`, `source_id`),
  KEY `accounting_vouchers_reverses_fk` (`reverses_voucher_id`),
  CONSTRAINT `accounting_vouchers_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `accounting_vouchers_reverses_fk` FOREIGN KEY (`reverses_voucher_id`) REFERENCES `accounting_vouchers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accounting_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `voucher_id` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `description` varchar(255) DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `accounting_lines_voucher_fk` (`voucher_id`),
  KEY `accounting_lines_account_fk` (`account_id`),
  CONSTRAINT `accounting_lines_voucher_fk` FOREIGN KEY (`voucher_id`) REFERENCES `accounting_vouchers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `accounting_lines_account_fk` FOREIGN KEY (`account_id`) REFERENCES `accounting_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
