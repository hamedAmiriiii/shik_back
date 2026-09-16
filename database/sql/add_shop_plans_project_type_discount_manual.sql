-- تعرفه جدا برای فروشگاه / تعویض روغن + مبلغ فروش و مبلغ با تخفیف
-- معادل migration 2026_09_16_140000_add_project_type_and_discount_to_shop_plans
-- اگر ستون از قبل هست، خط مربوطه را رد کنید.

SET NAMES utf8mb4;

ALTER TABLE `shop_plans`
  ADD COLUMN `project_type` varchar(16) NOT NULL DEFAULT 'shop' AFTER `name`;

ALTER TABLE `shop_plans`
  ADD COLUMN `discount_price_rial` bigint unsigned NULL DEFAULT NULL AFTER `price_rial`;

ALTER TABLE `shop_plans`
  ADD COLUMN `description` varchar(500) NULL DEFAULT NULL AFTER `discount_price_rial`;
