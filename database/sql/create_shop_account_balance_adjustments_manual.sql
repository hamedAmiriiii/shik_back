CREATE TABLE IF NOT EXISTS `shop_account_balance_adjustments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `atelier_id` bigint unsigned NOT NULL,
  `shop_account_id` bigint unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `date` date NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `saba_atelier_account_idx` (`atelier_id`, `shop_account_id`),
  CONSTRAINT `saba_atelier_fk` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `saba_account_fk` FOREIGN KEY (`shop_account_id`) REFERENCES `shop_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
