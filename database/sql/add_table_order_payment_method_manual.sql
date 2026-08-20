-- روش پرداخت سفارش پای میز (معادل 2026_08_20_110000)
-- اگر جدول table_orders از قبل ساخته شده، همین را اجرا کنید.

ALTER TABLE `table_orders`
    ADD COLUMN `payment_method` VARCHAR(30) NULL AFTER `use_credit`;
