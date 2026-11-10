-- تخفیف هر ردیف فاکتور (فاکتور با جزئیات)
ALTER TABLE `invoice_items`
  ADD COLUMN `discount_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `vat_amount`;
