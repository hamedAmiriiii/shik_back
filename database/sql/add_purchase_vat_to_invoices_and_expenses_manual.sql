-- ارزش افزوده خرید روی فاکتور و هزینه — هر دستور را یک‌بار اجرا کنید؛ Duplicate را نادیده بگیرید.

ALTER TABLE `invoices`
  ADD COLUMN `vat_mode` VARCHAR(16) NOT NULL DEFAULT 'none' AFTER `amount`;
ALTER TABLE `invoices`
  ADD COLUMN `vat_percent` DECIMAL(5,2) NULL AFTER `vat_mode`;
ALTER TABLE `invoices`
  ADD COLUMN `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `vat_percent`;

ALTER TABLE `expenses`
  ADD COLUMN `vat_mode` VARCHAR(16) NOT NULL DEFAULT 'none' AFTER `amount`;
ALTER TABLE `expenses`
  ADD COLUMN `vat_percent` DECIMAL(5,2) NULL AFTER `vat_mode`;
ALTER TABLE `expenses`
  ADD COLUMN `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `vat_percent`;
