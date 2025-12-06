-- SQL ساده برای اضافه کردن purchase_id (اگر قبلاً وجود ندارد)
-- ابتدا بررسی کنید که فیلد وجود دارد یا نه

-- اگر فیلد purchase_id وجود ندارد، این دستور را اجرا کنید:
-- ALTER TABLE `purchased_products` 
--   ADD COLUMN `purchase_id` BIGINT UNSIGNED NULL AFTER `id`,
--   ADD CONSTRAINT `purchased_products_purchase_id_foreign` 
--     FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) 
--     ON DELETE CASCADE;

-- اگر فیلد purchase_id وجود دارد اما foreign key ندارد، فقط constraint را اضافه کنید:
-- ALTER TABLE `purchased_products` 
--   ADD CONSTRAINT `purchased_products_purchase_id_foreign` 
--     FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) 
--     ON DELETE CASCADE;

-- برای بررسی اینکه آیا فیلد purchase_id وجود دارد:
SELECT COLUMN_NAME 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'purchased_products' 
  AND COLUMN_NAME = 'purchase_id';

-- برای بررسی اینکه آیا foreign key وجود دارد:
SELECT CONSTRAINT_NAME 
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'purchased_products' 
  AND CONSTRAINT_NAME = 'purchased_products_purchase_id_foreign';

