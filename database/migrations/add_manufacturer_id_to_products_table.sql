-- اضافه کردن ستون manufacturer_id به جدول products
ALTER TABLE `products` 
  ADD COLUMN `manufacturer_id` BIGINT UNSIGNED NULL AFTER `name`,
  ADD CONSTRAINT `products_manufacturer_id_foreign` 
    FOREIGN KEY (`manufacturer_id`) 
    REFERENCES `manufacturers` (`id`) 
    ON DELETE SET NULL;

-- برای rollback:
-- ALTER TABLE `products` 
--   DROP FOREIGN KEY `products_manufacturer_id_foreign`,
--   DROP COLUMN `manufacturer_id`;

