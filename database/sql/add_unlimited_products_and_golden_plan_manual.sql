-- سقف ۱۰۰۰ کالا + اشتراک طلایی (بدون سقف محصول) ۸ میلیون تومان
-- معادل migration 2026_09_17_180000_add_unlimited_products_and_golden_plan

SET NAMES utf8mb4;

-- اگر ستون از قبل هست، خط مربوطه را رد کنید.
ALTER TABLE `ateliers`
  ADD COLUMN `unlimited_products` TINYINT(1) NOT NULL DEFAULT 0 AFTER `subscription_renewal_days`;

ALTER TABLE `shop_plans`
  ADD COLUMN `unlimited_products` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`;

-- اشتراک طلایی فروشگاه (۸ میلیون تومان = ۸۰٬۰۰۰٬۰۰۰ ریال)
INSERT INTO `shop_plans` (
  `name`,
  `project_type`,
  `duration_days`,
  `price_rial`,
  `discount_price_rial`,
  `description`,
  `is_active`,
  `unlimited_products`,
  `sort_order`,
  `created_at`,
  `updated_at`
)
SELECT
  'اشتراک طلایی',
  'shop',
  365,
  80000000,
  NULL,
  'بدون سقف تعداد کالا — مناسب فروشگاه‌های با بیش از ۱۰۰۰ محصول',
  1,
  1,
  100,
  NOW(),
  NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `shop_plans`
  WHERE `name` = 'اشتراک طلایی' AND `project_type` = 'shop'
);
