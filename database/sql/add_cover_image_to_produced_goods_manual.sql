-- عکس کلی کالای تولیدی (بدون لایه‌های مواد)
ALTER TABLE `produced_goods`
  ADD COLUMN `cover_image_path` VARCHAR(255) NULL AFTER `layer_compose`;
