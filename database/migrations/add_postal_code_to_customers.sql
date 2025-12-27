-- SQL برای اضافه کردن فیلد postal_code به جدول customers

ALTER TABLE `customers` 
  ADD COLUMN `postal_code` VARCHAR(10) NULL AFTER `address`;

