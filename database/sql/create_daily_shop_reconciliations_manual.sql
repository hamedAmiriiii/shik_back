-- تطبیق روزانه فروشگاه — واریز حساب ۱، حساب ۲، نقدی
CREATE TABLE IF NOT EXISTS `daily_shop_reconciliations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `total_sales` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `card_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `cash_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `installments_collected` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `total_collected` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `credit_used_total` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `settlement_total` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `deposit_account_1` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `deposit_account_2` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `deposit_cash` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `deposited_total` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `daily_discrepancy` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `notes` TEXT NULL,
  `user_name` VARCHAR(255) NULL,
  `invoice_account_1_id` BIGINT UNSIGNED NULL,
  `invoice_account_2_id` BIGINT UNSIGNED NULL,
  `invoice_cash_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `daily_shop_reconciliations_atelier_id_date_unique` (`atelier_id`, `date`),
  KEY `daily_shop_reconciliations_atelier_id_foreign` (`atelier_id`),
  CONSTRAINT `daily_shop_reconciliations_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `daily_shop_reconciliations_invoice_account_1_id_foreign`
    FOREIGN KEY (`invoice_account_1_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `daily_shop_reconciliations_invoice_account_2_id_foreign`
    FOREIGN KEY (`invoice_account_2_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `daily_shop_reconciliations_invoice_cash_id_foreign`
    FOREIGN KEY (`invoice_cash_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
