-- اگر جدول daily_shop_reconciliations از قبل ساخته شده:
ALTER TABLE `daily_shop_reconciliations`
  ADD COLUMN `credit_used_total` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `total_collected`,
  ADD COLUMN `settlement_total` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `credit_used_total`;
