-- اضافه کردن ستینگ نرخ سود ماهانه برای خریدهای اقساطی
-- این ستینگ به صورت درصد ذخیره می‌شود (مثلاً 2 برای 2%)

INSERT INTO `settings` (`key`, `value`, `created_at`, `updated_at`) 
VALUES ('installment_monthly_interest_rate', '2', NOW(), NOW())
ON DUPLICATE KEY UPDATE `value` = '2', `updated_at` = NOW();

-- برای تغییر مقدار:
-- UPDATE `settings` SET `value` = '2' WHERE `key` = 'installment_monthly_interest_rate';
-- (مثال: 2 به معنای 2% سود ماهانه)

-- برای حذف:
-- DELETE FROM `settings` WHERE `key` = 'installment_monthly_interest_rate';

