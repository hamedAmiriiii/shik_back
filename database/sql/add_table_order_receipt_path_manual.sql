-- رسید کارت‌به‌کارت سفارش پای میز (معادل 2026_08_20_120000)

ALTER TABLE `table_orders`
    ADD COLUMN `receipt_path` VARCHAR(255) NULL AFTER `payment_method`;
