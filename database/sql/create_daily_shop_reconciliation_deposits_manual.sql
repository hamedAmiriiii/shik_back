-- ============================================================
-- ترتیب اجرا برای جابجایی داده‌های قبلی:
-- 1) CREATE TABLE
-- 2) INSERT از invoices
-- 3) ALTER (FK + rename) — فقط اگر هنوز rename نشده
-- 4) DELETE از invoices
-- ============================================================

-- مرحله ۱: ساخت جدول واریزهای تطبیق
CREATE TABLE IF NOT EXISTS `daily_shop_reconciliation_deposits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15, 2) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `date` DATE NOT NULL,
  `user_name` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `daily_shop_reconciliation_deposits_atelier_id_foreign` (`atelier_id`),
  CONSTRAINT `daily_shop_reconciliation_deposits_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- مرحله ۲: INSERT — یکی از دو نسخه زیر را بزن (بسته به وضعیت جدول)
-- ============================================================

-- نسخه A: اگر ستون‌ها هنوز invoice_* هستند (قبل از rename)
-- INSERT INTO `daily_shop_reconciliation_deposits`
--   (`id`, `atelier_id`, `amount`, `title`, `description`, `date`, `user_name`, `created_at`, `updated_at`)
-- SELECT
--   i.`id`, i.`atelier_id`, i.`amount`, i.`title`, i.`description`, i.`date`, i.`user_name`, i.`created_at`, i.`updated_at`
-- FROM `invoices` i
-- WHERE i.`id` IN (
--   SELECT `invoice_account_1_id` FROM `daily_shop_reconciliations` WHERE `invoice_account_1_id` IS NOT NULL
--   UNION
--   SELECT `invoice_account_2_id` FROM `daily_shop_reconciliations` WHERE `invoice_account_2_id` IS NOT NULL
--   UNION
--   SELECT `invoice_cash_id` FROM `daily_shop_reconciliations` WHERE `invoice_cash_id` IS NOT NULL
-- );

-- نسخه B: اگر ستون‌ها قبلاً rename شده‌اند (deposit_record_*)
INSERT INTO `daily_shop_reconciliation_deposits`
  (`id`, `atelier_id`, `amount`, `title`, `description`, `date`, `user_name`, `created_at`, `updated_at`)
SELECT
  i.`id`, i.`atelier_id`, i.`amount`, i.`title`, i.`description`, i.`date`, i.`user_name`, i.`created_at`, i.`updated_at`
FROM `invoices` i
WHERE i.`id` IN (
  SELECT `deposit_record_account_1_id` FROM `daily_shop_reconciliations` WHERE `deposit_record_account_1_id` IS NOT NULL
  UNION
  SELECT `deposit_record_account_2_id` FROM `daily_shop_reconciliations` WHERE `deposit_record_account_2_id` IS NOT NULL
  UNION
  SELECT `deposit_record_cash_id` FROM `daily_shop_reconciliations` WHERE `deposit_record_cash_id` IS NOT NULL
);

-- ============================================================
-- مرحله ۳: فقط اگر هنوز rename نشده — ALTER برای FK و rename
-- ============================================================
ALTER TABLE `daily_shop_reconciliations`
  DROP FOREIGN KEY `daily_shop_reconciliations_invoice_account_1_id_foreign`,
  DROP FOREIGN KEY `daily_shop_reconciliations_invoice_account_2_id_foreign`,
  DROP FOREIGN KEY `daily_shop_reconciliations_invoice_cash_id_foreign`;

ALTER TABLE `daily_shop_reconciliations`
  CHANGE COLUMN `invoice_account_1_id` `deposit_record_account_1_id` BIGINT UNSIGNED NULL,
  CHANGE COLUMN `invoice_account_2_id` `deposit_record_account_2_id` BIGINT UNSIGNED NULL,
  CHANGE COLUMN `invoice_cash_id` `deposit_record_cash_id` BIGINT UNSIGNED NULL;

ALTER TABLE `daily_shop_reconciliations`
  ADD CONSTRAINT `daily_shop_reconciliations_deposit_record_account_1_id_foreign`
    FOREIGN KEY (`deposit_record_account_1_id`) REFERENCES `daily_shop_reconciliation_deposits` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `daily_shop_reconciliations_deposit_record_account_2_id_foreign`
    FOREIGN KEY (`deposit_record_account_2_id`) REFERENCES `daily_shop_reconciliation_deposits` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `daily_shop_reconciliations_deposit_record_cash_id_foreign`
    FOREIGN KEY (`deposit_record_cash_id`) REFERENCES `daily_shop_reconciliation_deposits` (`id`) ON DELETE SET NULL;

-- ============================================================
-- مرحله ۴: حذف رکوردهای منتقل‌شده از invoices
-- ============================================================
-- DELETE FROM `invoices`
-- WHERE `id` IN (
--   SELECT `deposit_record_account_1_id` FROM `daily_shop_reconciliations` WHERE `deposit_record_account_1_id` IS NOT NULL
--   UNION
--   SELECT `deposit_record_account_2_id` FROM `daily_shop_reconciliations` WHERE `deposit_record_account_2_id` IS NOT NULL
--   UNION
--   SELECT `deposit_record_cash_id` FROM `daily_shop_reconciliations` WHERE `deposit_record_cash_id` IS NOT NULL
-- );
