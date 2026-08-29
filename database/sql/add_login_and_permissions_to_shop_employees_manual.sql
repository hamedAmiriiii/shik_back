-- ورود کارمند فروشگاه + لیست دسترسی بر اساس APIهای فروشگاه
-- معادل migration 2026_08_28_160000
-- هر دستور را جدا اجرا کنید. اگر Duplicate column آمد همان را رد کنید.

ALTER TABLE `shop_employees`
    ADD COLUMN `user_id` BIGINT UNSIGNED NULL AFTER `atelier_id`;

ALTER TABLE `shop_employees`
    ADD UNIQUE KEY `shop_employees_user_id_unique` (`user_id`);

-- اگر JSON در نسخه MySQL شما نیست، به‌جای آن TEXT بگذارید.
ALTER TABLE `shop_employees`
    ADD COLUMN `permissions` JSON NULL AFTER `note`;
