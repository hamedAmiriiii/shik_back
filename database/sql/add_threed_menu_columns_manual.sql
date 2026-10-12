-- منوی سه‌بعدی (تم menu سفارش میز)
-- هر دستور را یک‌بار اجرا کنید؛ اگر ستون از قبل هست خطای Duplicate column می‌دهد و می‌توانید نادیده بگیرید.

ALTER TABLE `raw_materials` ADD COLUMN `layer_image_path` VARCHAR(255) NULL;

ALTER TABLE `produced_goods` ADD COLUMN `layer_order` TEXT NULL;

ALTER TABLE `produced_good_ingredients` ADD COLUMN `is_removable` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `table_order_items` ADD COLUMN `removed_ingredients` TEXT NULL;
