-- مبلغ اشتراک اختصاصی هر فروشگاه (قیمت فعلی + قیمت تمدید بعدی)
-- روی دیتابیس atelier اجرا شود.
-- اگر ستونی از قبل وجود داشت، همان خط را حذف کنید و بقیه را اجرا کنید.

ALTER TABLE `ateliers`
  ADD COLUMN `subscription_current_price_rial` BIGINT UNSIGNED NULL AFTER `paid_plan_activated_at`,
  ADD COLUMN `subscription_renewal_price_rial` BIGINT UNSIGNED NULL AFTER `subscription_current_price_rial`,
  ADD COLUMN `subscription_renewal_days` INT UNSIGNED NULL AFTER `subscription_renewal_price_rial`;
