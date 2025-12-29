-- اضافه کردن فیلدهای sizes و colors به جدول products
-- هر دو به صورت JSON ذخیره می‌شوند

ALTER TABLE `products` 
ADD COLUMN `sizes` JSON NULL AFTER `quantity`,
ADD COLUMN `colors` JSON NULL AFTER `sizes`;

-- مثال استفاده:
-- INSERT: sizes = '["S", "M", "L", "XL"]', colors = '["قرمز", "آبی", "سبز"]'
-- UPDATE: UPDATE products SET sizes = '["36", "38", "40"]', colors = '["مشکی", "سفید"]' WHERE id = 1;

