-- اضافه کردن فیلد cart_id به جدول purchases
-- برای ارتباط بین Purchase و Cart (سفارش اینترنتی)

ALTER TABLE `purchases` 
ADD COLUMN `cart_id` BIGINT UNSIGNED NULL AFTER `id`,
ADD INDEX `purchases_cart_id_index` (`cart_id`),
ADD CONSTRAINT `purchases_cart_id_foreign` 
  FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) 
  ON DELETE SET NULL;

