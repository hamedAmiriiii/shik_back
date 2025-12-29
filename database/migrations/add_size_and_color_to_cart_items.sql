-- اضافه کردن فیلدهای size و color به جدول cart_items
-- برای ثبت سایز و رنگ انتخاب شده توسط مشتری

ALTER TABLE `cart_items` 
ADD COLUMN `size` VARCHAR(255) NULL AFTER `price`,
ADD COLUMN `color` VARCHAR(255) NULL AFTER `size`;

