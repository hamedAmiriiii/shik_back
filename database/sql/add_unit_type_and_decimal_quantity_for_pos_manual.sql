-- فروش کیلویی در POS: نوع واحد محصول + موجودی/فروش اعشاری

ALTER TABLE `products`
  ADD COLUMN `unit_type` VARCHAR(10) NOT NULL DEFAULT 'piece' AFTER `quantity`;

ALTER TABLE `products`
  MODIFY `quantity` DECIMAL(12, 3) NOT NULL DEFAULT 0;

ALTER TABLE `purchased_products`
  MODIFY `quantity` DECIMAL(12, 3) NOT NULL DEFAULT 1;

ALTER TABLE `purchase_item_returns`
  MODIFY `quantity` DECIMAL(12, 3) NOT NULL;
