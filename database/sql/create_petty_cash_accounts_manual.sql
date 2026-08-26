-- تنخواه: نوع حساب + شارژ از حساب اصلی + حساب برداشت روی هزینه و فاکتور
-- داده‌های قبلی حفظ می‌شوند؛ همه حساب‌های موجود type='shop' می‌گیرند.

-- 1) ستون نوع حساب روی shop_accounts
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'shop_accounts'
    AND COLUMN_NAME = 'type'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `shop_accounts`
     ADD COLUMN `type` varchar(32) NOT NULL DEFAULT ''shop''
       COMMENT ''shop=حساب اصلی فروشگاه | petty_cash=تنخواه'' AFTER `name`,
     ADD INDEX `shop_accounts_atelier_id_type_index` (`atelier_id`, `type`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `shop_accounts` SET `type` = 'shop' WHERE `type` IS NULL OR `type` = '';

-- 2) جدول شارژ تنخواه از حساب اصلی
CREATE TABLE IF NOT EXISTS `shop_account_transfers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `atelier_id` bigint unsigned NOT NULL,
  `from_shop_account_id` bigint unsigned NOT NULL,
  `to_shop_account_id` bigint unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `date` date NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text,
  `user_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shop_account_transfers_atelier_id_date_index` (`atelier_id`, `date`),
  KEY `sat_from_account_fk` (`from_shop_account_id`),
  KEY `sat_to_account_fk` (`to_shop_account_id`),
  CONSTRAINT `shop_account_transfers_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sat_from_account_fk` FOREIGN KEY (`from_shop_account_id`) REFERENCES `shop_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sat_to_account_fk` FOREIGN KEY (`to_shop_account_id`) REFERENCES `shop_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) حساب برداشت روی هزینه‌ها
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'expenses'
    AND COLUMN_NAME = 'shop_account_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `expenses`
     ADD COLUMN `shop_account_id` bigint unsigned NULL
       COMMENT ''حسابی که مبلغ از آن برداشت شده است'' AFTER `atelier_id`,
     ADD CONSTRAINT `exp_shop_account_fk` FOREIGN KEY (`shop_account_id`) REFERENCES `shop_accounts` (`id`) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) حساب پرداخت روی فاکتورها
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'invoices'
    AND COLUMN_NAME = 'shop_account_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `invoices`
     ADD COLUMN `shop_account_id` bigint unsigned NULL
       COMMENT ''حسابی که مبلغ از آن پرداخت شده است'' AFTER `atelier_id`,
     ADD CONSTRAINT `inv_shop_account_fk` FOREIGN KEY (`shop_account_id`) REFERENCES `shop_accounts` (`id`) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
