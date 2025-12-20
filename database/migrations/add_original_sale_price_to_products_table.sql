-- اضافه کردن ستون original_sale_price به جدول products
-- برای ذخیره قیمت اصلی قبل از تخفیف

ALTER TABLE `products` 
  ADD COLUMN `original_sale_price` DECIMAL(15, 2) NULL AFTER `sale_price`;

-- به‌روزرسانی داده‌های موجود: original_sale_price را برابر sale_price قرار بده
UPDATE `products` 
SET `original_sale_price` = `sale_price`
WHERE `original_sale_price` IS NULL;

-- برای rollback:
-- ALTER TABLE `products` DROP COLUMN `original_sale_price`;

