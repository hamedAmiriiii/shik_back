-- سالن/بیرون‌بر و توضیحات سفارش روی فروش

ALTER TABLE `purchases`
  ADD COLUMN `dining_mode` VARCHAR(20) NULL AFTER `table_label`;

ALTER TABLE `purchases`
  ADD COLUMN `customer_note` TEXT NULL AFTER `dining_mode`;
