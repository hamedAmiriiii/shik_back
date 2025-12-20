-- اضافه کردن ستون sale_price به جدول purchased_products
-- برای ذخیره قیمت واقعی فروش (با تخفیف)

ALTER TABLE `purchased_products` 
  ADD COLUMN `sale_price` DECIMAL(15, 2) NULL AFTER `purchase_price`;

-- به‌روزرسانی داده‌های موجود: اگر sale_price NULL باشد، از sale_price محصول استفاده کن
UPDATE `purchased_products` pp
INNER JOIN `products` p ON pp.product_id = p.id
SET pp.sale_price = p.sale_price
WHERE pp.sale_price IS NULL;

-- برای rollback:
-- ALTER TABLE `purchased_products` DROP COLUMN `sale_price`;

