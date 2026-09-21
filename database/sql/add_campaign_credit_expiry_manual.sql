-- مهلت استفاده اعتبار هدیه کمپین

ALTER TABLE `user_credit_grants`
  ADD COLUMN `remaining` DECIMAL(15, 2) NULL DEFAULT NULL AFTER `amount`,
  ADD COLUMN `expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `remaining`,
  ADD COLUMN `campaign_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `purchase_id`;
