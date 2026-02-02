-- اضافه کردن فیلدهای مربوط به انقضای اعتبار به جدول user_shiksho

-- اضافه کردن فیلد credit_last_updated_at
ALTER TABLE `user_shiksho` 
  ADD COLUMN `credit_last_updated_at` TIMESTAMP NULL AFTER `credit`,
  ADD COLUMN `last_warning_sent_at` TIMESTAMP NULL AFTER `credit_last_updated_at`;

-- برای رکوردهای موجود، credit_last_updated_at را برابر updated_at قرار بده
UPDATE `user_shiksho` 
SET `credit_last_updated_at` = `updated_at` 
WHERE `credit_last_updated_at` IS NULL;

-- ایجاد Setting پیش‌فرض برای تعداد روز انقضا
INSERT INTO `settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES ('credit_expiry_days', '60', NOW(), NOW())
ON DUPLICATE KEY UPDATE `value` = '60';

-- برای rollback:
-- ALTER TABLE `user_shiksho` 
--   DROP COLUMN `credit_last_updated_at`,
--   DROP COLUMN `last_warning_sent_at`;

