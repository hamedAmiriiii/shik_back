-- لاگ اصلاح مانده: کاربر و مانده قبل/بعد
-- اگر ستون از قبل وجود دارد، خط مربوط را رد کنید.

ALTER TABLE `shop_account_balance_adjustments`
  ADD COLUMN `user_id` bigint unsigned NULL DEFAULT NULL AFTER `shop_account_id`,
  ADD COLUMN `user_name` varchar(120) NULL DEFAULT NULL AFTER `user_id`,
  ADD COLUMN `balance_before` decimal(15,2) NULL DEFAULT NULL AFTER `amount`,
  ADD COLUMN `balance_after` decimal(15,2) NULL DEFAULT NULL AFTER `balance_before`;
