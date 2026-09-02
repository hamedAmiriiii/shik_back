-- قیمت خرید/فروش محصولات روغن + اتصال تعویض به فاکتور فروش (purchases)
-- معادل migration 2026_09_02_190000_add_oil_product_prices_and_visit_purchase

SET NAMES utf8mb4;

SET @has_buy := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oil_products' AND COLUMN_NAME = 'purchase_price'
);
SET @sql := IF(@has_buy = 0,
  'ALTER TABLE `oil_products` ADD COLUMN `purchase_price` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `name`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_sale := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oil_products' AND COLUMN_NAME = 'sale_price'
);
SET @sql := IF(@has_sale = 0,
  'ALTER TABLE `oil_products` ADD COLUMN `sale_price` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `purchase_price`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_item_buy := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oil_visit_items' AND COLUMN_NAME = 'purchase_price'
);
SET @sql := IF(@has_item_buy = 0,
  'ALTER TABLE `oil_visit_items` ADD COLUMN `purchase_price` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `product_name`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_item_sale := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oil_visit_items' AND COLUMN_NAME = 'sale_price'
);
SET @sql := IF(@has_item_sale = 0,
  'ALTER TABLE `oil_visit_items` ADD COLUMN `sale_price` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `purchase_price`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_visit_fk := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'oil_visit_id'
);
SET @sql := IF(@has_visit_fk = 0,
  'ALTER TABLE `purchases` ADD COLUMN `oil_visit_id` BIGINT UNSIGNED NULL, ADD UNIQUE KEY `purchases_oil_visit_id_unique` (`oil_visit_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
