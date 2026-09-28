-- DECIMAL(10,2) max is 99,999,999.99 — a product priced 160,000,000 fails with error 1264 (out of range).
-- Align every amount column with the rest of the schema (DECIMAL(15,2)).
-- Check current definitions first:
--   SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
--   FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'decimal' AND NUMERIC_PRECISION = 10;

ALTER TABLE `products`
  MODIFY COLUMN `purchase_price` DECIMAL(15, 2) NOT NULL,
  MODIFY COLUMN `sale_price` DECIMAL(15, 2) NOT NULL;

ALTER TABLE `purchased_products`
  MODIFY COLUMN `purchase_price` DECIMAL(15, 2) NOT NULL;

ALTER TABLE `purchases`
  MODIFY COLUMN `installment_amount` DECIMAL(15, 2) NULL;

ALTER TABLE `installments`
  MODIFY COLUMN `amount` DECIMAL(15, 2) NOT NULL;

ALTER TABLE `expenses`
  MODIFY COLUMN `amount` DECIMAL(15, 2) NOT NULL;
