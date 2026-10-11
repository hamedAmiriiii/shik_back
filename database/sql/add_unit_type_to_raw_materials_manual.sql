-- واحد ماده اولیه: kg (کیلو، پیش‌فرض) یا piece (عدد)
-- معادل migration 2026_10_11_100000
-- اگر خطای Duplicate column (#1060) آمد، یعنی ستون از قبل هست و نیازی به اجرا نیست.

ALTER TABLE `raw_materials`
    ADD COLUMN `unit_type` VARCHAR(10) NOT NULL DEFAULT 'kg' AFTER `name`;
