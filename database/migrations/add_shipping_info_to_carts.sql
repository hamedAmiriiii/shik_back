-- SQL برای اضافه کردن فیلدهای اطلاعات ارسال به جدول carts

ALTER TABLE `carts` 
  ADD COLUMN `shipping_name` VARCHAR(255) NULL AFTER `status`,
  ADD COLUMN `shipping_last_name` VARCHAR(255) NULL AFTER `shipping_name`,
  ADD COLUMN `shipping_phone` VARCHAR(11) NULL AFTER `shipping_last_name`,
  ADD COLUMN `shipping_address` TEXT NULL AFTER `shipping_phone`,
  ADD COLUMN `shipping_state_id` BIGINT UNSIGNED NULL AFTER `shipping_address`,
  ADD COLUMN `shipping_state_name` VARCHAR(255) NULL AFTER `shipping_state_id`,
  ADD COLUMN `shipping_city_id` BIGINT UNSIGNED NULL AFTER `shipping_state_name`,
  ADD COLUMN `shipping_city_name` VARCHAR(255) NULL AFTER `shipping_city_id`,
  ADD COLUMN `shipping_postal_code` VARCHAR(10) NULL AFTER `shipping_city_name`;

