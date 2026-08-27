-- قیمت فروش دو حالته برای کالاهای تولیدی
-- معادل migration 2026_08_27_100000
-- اگر ستون از قبل هست، این ALTER را رد کنید.
-- markup_percent پر باشد یعنی فروش = قیمت تمام‌شده + همین درصد (بعد از تولید بعدی به‌روز می‌شود)
-- NULL یعنی قیمت فروش دستی است و خودکار عوض نمی‌شود.

ALTER TABLE `produced_goods`
    ADD COLUMN `markup_percent` DECIMAL(8,2) NULL AFTER `sale_price`;
