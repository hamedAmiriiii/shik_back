-- مبلغ اشتراک اختصاصی هر فروشگاه (قیمت فعلی + قیمت تمدید بعدی)
-- روی دیتابیس atelier اجرا شود.

ALTER TABLE `ateliers`
  ADD COLUMN IF NOT EXISTS `subscription_current_price_rial` BIGINT UNSIGNED NULL AFTER `paid_plan_activated_at`,
  ADD COLUMN IF NOT EXISTS `subscription_renewal_price_rial` BIGINT UNSIGNED NULL AFTER `subscription_current_price_rial`,
  ADD COLUMN IF NOT EXISTS `subscription_renewal_days` INT UNSIGNED NULL AFTER `subscription_renewal_price_rial`;

-- اگر MySQL قدیمی IF NOT EXISTS برای ADD COLUMN ندارد، از این نسخه استفاده کنید:
-- ALTER TABLE `ateliers`
--   ADD COLUMN `subscription_current_price_rial` BIGINT UNSIGNED NULL AFTER `paid_plan_activated_at`,
--   ADD COLUMN `subscription_renewal_price_rial` BIGINT UNSIGNED NULL AFTER `subscription_current_price_rial`,
--   ADD COLUMN `subscription_renewal_days` INT UNSIGNED NULL AFTER `subscription_renewal_price_rial`;
