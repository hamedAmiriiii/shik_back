-- اضافه کردن فیلدهای size و color به جدول purchased_products
-- برای ثبت سایز و رنگ انتخاب شده توسط مشتری

ALTER TABLE `purchased_products` 
ADD COLUMN `size` VARCHAR(255) NULL AFTER `sale_price`,
ADD COLUMN `color` VARCHAR(255) NULL AFTER `size`;

-- مثال استفاده:
-- UPDATE purchased_products SET size = 'L', color = 'قرمز' WHERE id = 1;

