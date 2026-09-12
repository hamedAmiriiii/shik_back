-- ترتیب نمایش کالا در منوی میز/اتاق و کاتالوگ (کمتر = بالاتر؛ پیش‌فرض ۵۰)
ALTER TABLE `products`
  ADD COLUMN `display_order` SMALLINT UNSIGNED NOT NULL DEFAULT 50 AFTER `description`,
  ADD INDEX `products_atelier_display_order_index` (`atelier_id`, `display_order`);

-- کالای تولیدی هم در همان منو می‌آید
ALTER TABLE `produced_goods`
  ADD COLUMN `display_order` SMALLINT UNSIGNED NOT NULL DEFAULT 50 AFTER `name`,
  ADD INDEX `produced_goods_atelier_display_order_index` (`atelier_id`, `display_order`);
