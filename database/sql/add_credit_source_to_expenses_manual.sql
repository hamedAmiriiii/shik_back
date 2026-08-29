-- منبع هزینهٔ اعتبار مشتری (وفاداری / برگشت خرید / افزایش دستی)
-- معادل migration 2026_08_29_140000
-- هر دستور را جدا اجرا کنید. اگر Duplicate column / Duplicate key آمد همان را رد کنید.

ALTER TABLE `expenses`
    ADD COLUMN `credit_source` VARCHAR(32) NULL AFTER `title`;

ALTER TABLE `expenses`
    ADD COLUMN `credit_source_id` BIGINT UNSIGNED NULL AFTER `credit_source`;

ALTER TABLE `expenses`
    ADD INDEX `expenses_credit_source_index` (`atelier_id`, `credit_source`, `credit_source_id`);
