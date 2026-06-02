ALTER TABLE `daily_shop_reconciliations`
  ADD COLUMN `discount_given` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `settlement_total`;
