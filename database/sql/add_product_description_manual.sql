-- توضیح اختیاری کالا برای نمایش در منوی میز/اتاق
ALTER TABLE `products`
  ADD COLUMN `description` VARCHAR(500) NULL DEFAULT NULL AFTER `name`;
