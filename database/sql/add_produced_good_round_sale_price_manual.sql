-- رند کردن قیمت فروش کالای تولیدی به نزدیک‌ترین هزار تومان
-- معادل migration 2026_08_27_110000
-- اگر ستون از قبل هست، این ALTER را رد کنید.
-- round_sale_price = 1 یعنی مثلاً 32560 می‌شود 33000
-- round_sale_price = 0 یعنی همان مبلغ دقیق ذخیره می‌شود

ALTER TABLE `produced_goods`
    ADD COLUMN `round_sale_price` TINYINT(1) NOT NULL DEFAULT 0 AFTER `markup_percent`;
