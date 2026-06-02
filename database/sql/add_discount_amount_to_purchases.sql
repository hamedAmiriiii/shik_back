ALTER TABLE `purchases`
  ADD COLUMN `discount_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `total_amount`;
