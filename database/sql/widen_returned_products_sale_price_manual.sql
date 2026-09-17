-- sale_price was DECIMAL(10,2) (max ~99,999,999.99) — high-value returns fail with 1264.
-- Align with purchase_item_returns / purchased_products (DECIMAL(15,2)).

ALTER TABLE `returned_products`
  MODIFY COLUMN `sale_price` DECIMAL(15, 2) NOT NULL;
