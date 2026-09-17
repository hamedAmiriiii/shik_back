-- مقصد برگشت پول: نقد از صندوق، کارت به اعتبار مشتری یا برداشت از حساب
ALTER TABLE `purchase_item_returns`
  ADD COLUMN `cash_refunded` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `credit_earned_reversed`,
  ADD COLUMN `card_refunded` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `cash_refunded`,
  ADD COLUMN `card_refund_destination` VARCHAR(32) NULL AFTER `card_refunded`,
  ADD COLUMN `shop_account_id` BIGINT UNSIGNED NULL AFTER `card_refund_destination`;
