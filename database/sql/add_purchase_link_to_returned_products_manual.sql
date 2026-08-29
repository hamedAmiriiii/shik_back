-- ستون‌های اختیاری برگشت کالا از فاکتور
-- هر دستور را جدا اجرا کنید. اگر Duplicate column آمد همان را رد کنید.

ALTER TABLE `returned_products`
    ADD COLUMN `purchase_id` BIGINT UNSIGNED NULL AFTER `atelier_id`;

ALTER TABLE `returned_products`
    ADD COLUMN `phone` VARCHAR(20) NULL AFTER `purchase_id`;

ALTER TABLE `returned_products`
    ADD COLUMN `quantity` DECIMAL(12,3) NOT NULL DEFAULT 1 AFTER `phone`;

ALTER TABLE `returned_products`
    ADD COLUMN `credit_refunded` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `purchase_price`;

ALTER TABLE `returned_products`
    ADD COLUMN `credit_earned_reversed` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `credit_refunded`;
