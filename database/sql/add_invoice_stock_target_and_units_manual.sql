-- یک‌بار اجرا شود.
-- واحد مواد مصرفی (عدد / کیلو / لیتر)، واحد ردیف فاکتور، و نوع موجودی فاکتور.

ALTER TABLE `consumable_materials`
  ADD COLUMN `unit_type` VARCHAR(16) NOT NULL DEFAULT 'piece' AFTER `name`;

ALTER TABLE `invoice_items`
  ADD COLUMN `unit_type` VARCHAR(16) NULL AFTER `quantity`;

ALTER TABLE `invoices`
  ADD COLUMN `stock_target` VARCHAR(16) NULL AFTER `title`;
