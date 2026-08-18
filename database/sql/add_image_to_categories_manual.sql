-- عکس دسته‌بندی
-- معادل migration: 2026_08_18_100000_add_image_to_categories_table

ALTER TABLE `categories`
  ADD COLUMN `image` VARCHAR(255) NULL DEFAULT NULL AFTER `description`;
