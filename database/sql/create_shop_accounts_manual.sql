-- حساب‌های فروشگاه + واریزهای تطبیق روزانه به تفکیک حساب
-- داده‌های قبلی (deposit_account_1 / deposit_account_2) حفظ و به ردیف‌های جدید منتقل می‌شوند.

CREATE TABLE IF NOT EXISTS `shop_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `atelier_id` bigint unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT 0,
  `legacy_slot` varchar(32) DEFAULT NULL COMMENT 'account_1 | account_2',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shop_accounts_atelier_id_legacy_slot_unique` (`atelier_id`, `legacy_slot`),
  KEY `shop_accounts_atelier_id_is_active_index` (`atelier_id`, `is_active`),
  CONSTRAINT `shop_accounts_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `daily_shop_reconciliation_account_deposits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `reconciliation_id` bigint unsigned NOT NULL,
  `shop_account_id` bigint unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `deposit_record_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dsrad_recon_account_unique` (`reconciliation_id`, `shop_account_id`),
  KEY `dsrad_account_fk` (`shop_account_id`),
  KEY `dsrad_deposit_fk` (`deposit_record_id`),
  CONSTRAINT `dsrad_recon_fk` FOREIGN KEY (`reconciliation_id`) REFERENCES `daily_shop_reconciliations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dsrad_account_fk` FOREIGN KEY (`shop_account_id`) REFERENCES `shop_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dsrad_deposit_fk` FOREIGN KEY (`deposit_record_id`) REFERENCES `daily_shop_reconciliation_deposits` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ستون اتصال روی رکورد واریز
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'daily_shop_reconciliation_deposits'
    AND COLUMN_NAME = 'shop_account_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `daily_shop_reconciliation_deposits`
     ADD COLUMN `shop_account_id` bigint unsigned NULL AFTER `atelier_id`,
     ADD CONSTRAINT `dsrd_shop_account_fk` FOREIGN KEY (`shop_account_id`) REFERENCES `shop_accounts` (`id`) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- حساب‌های پیش‌فرض برای همه فروشگاه‌ها
INSERT INTO `shop_accounts` (`atelier_id`, `name`, `sort_order`, `legacy_slot`, `is_active`, `created_at`, `updated_at`)
SELECT a.id, 'حساب ۱', 1, 'account_1', 1, NOW(), NOW()
FROM `ateliers` a
WHERE NOT EXISTS (
  SELECT 1 FROM `shop_accounts` sa
  WHERE sa.atelier_id = a.id AND sa.legacy_slot = 'account_1'
);

INSERT INTO `shop_accounts` (`atelier_id`, `name`, `sort_order`, `legacy_slot`, `is_active`, `created_at`, `updated_at`)
SELECT a.id, 'حساب ۲', 2, 'account_2', 1, NOW(), NOW()
FROM `ateliers` a
WHERE NOT EXISTS (
  SELECT 1 FROM `shop_accounts` sa
  WHERE sa.atelier_id = a.id AND sa.legacy_slot = 'account_2'
);

-- انتقال واریزهای قبلی حساب ۱ (با FK امن)
INSERT INTO `daily_shop_reconciliation_account_deposits`
  (`reconciliation_id`, `shop_account_id`, `amount`, `deposit_record_id`, `created_at`, `updated_at`)
SELECT
  r.id,
  sa.id,
  r.deposit_account_1,
  CASE
    WHEN r.deposit_record_account_1_id IS NOT NULL
      AND EXISTS (
        SELECT 1 FROM `daily_shop_reconciliation_deposits` d
        WHERE d.id = r.deposit_record_account_1_id
      )
    THEN r.deposit_record_account_1_id
    ELSE NULL
  END,
  NOW(),
  NOW()
FROM `daily_shop_reconciliations` r
INNER JOIN `shop_accounts` sa
  ON sa.atelier_id = r.atelier_id AND sa.legacy_slot = 'account_1'
WHERE (r.deposit_account_1 > 0 OR r.deposit_record_account_1_id IS NOT NULL)
  AND NOT EXISTS (
    SELECT 1 FROM `daily_shop_reconciliation_account_deposits` d
    WHERE d.reconciliation_id = r.id AND d.shop_account_id = sa.id
  );

-- انتقال واریزهای قبلی حساب ۲ (با FK امن)
INSERT INTO `daily_shop_reconciliation_account_deposits`
  (`reconciliation_id`, `shop_account_id`, `amount`, `deposit_record_id`, `created_at`, `updated_at`)
SELECT
  r.id,
  sa.id,
  r.deposit_account_2,
  CASE
    WHEN r.deposit_record_account_2_id IS NOT NULL
      AND EXISTS (
        SELECT 1 FROM `daily_shop_reconciliation_deposits` d
        WHERE d.id = r.deposit_record_account_2_id
      )
    THEN r.deposit_record_account_2_id
    ELSE NULL
  END,
  NOW(),
  NOW()
FROM `daily_shop_reconciliations` r
INNER JOIN `shop_accounts` sa
  ON sa.atelier_id = r.atelier_id AND sa.legacy_slot = 'account_2'
WHERE (r.deposit_account_2 > 0 OR r.deposit_record_account_2_id IS NOT NULL)
  AND NOT EXISTS (
    SELECT 1 FROM `daily_shop_reconciliation_account_deposits` d
    WHERE d.reconciliation_id = r.id AND d.shop_account_id = sa.id
  );

UPDATE `daily_shop_reconciliation_deposits` d
INNER JOIN `daily_shop_reconciliation_account_deposits` ad ON ad.deposit_record_id = d.id
SET d.shop_account_id = ad.shop_account_id
WHERE d.shop_account_id IS NULL;
