-- درخت حساب هر فروشگاه (گروه / کل / معین / تفصیلی)
-- بذر با ChartOfAccountsSeeder::ensureForAtelier بعد از ساخت جدول اعمال می‌شود.

CREATE TABLE IF NOT EXISTS `accounting_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `atelier_id` bigint unsigned NOT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(255) NOT NULL,
  `level` varchar(16) NOT NULL COMMENT 'group | kol | moein | tafsili',
  `nature` varchar(16) NOT NULL COMMENT 'debit | credit',
  `kind` varchar(16) NOT NULL COMMENT 'asset | liability | equity | revenue | cogs | expense',
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `linked_type` varchar(32) DEFAULT NULL COMMENT 'shop_account | till',
  `linked_id` bigint unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accounting_accounts_atelier_code_unique` (`atelier_id`, `code`),
  KEY `accounting_accounts_link_index` (`atelier_id`, `linked_type`, `linked_id`),
  KEY `accounting_accounts_atelier_id_level_index` (`atelier_id`, `level`),
  KEY `accounting_accounts_atelier_id_is_active_index` (`atelier_id`, `is_active`),
  KEY `accounting_accounts_parent_fk` (`parent_id`),
  CONSTRAINT `accounting_accounts_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `accounting_accounts_parent_fk` FOREIGN KEY (`parent_id`) REFERENCES `accounting_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
