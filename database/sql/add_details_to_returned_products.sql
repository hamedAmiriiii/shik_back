ALTER TABLE `returned_products`
  ADD COLUMN `purchase_price` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `sale_price`,
  ADD COLUMN `user_name` VARCHAR(255) NULL AFTER `purchase_price`,
  ADD COLUMN `notes` TEXT NULL AFTER `user_name`;
