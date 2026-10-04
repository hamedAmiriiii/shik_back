-- وصول نسیه: حسابی که پول به آن واریز شده
ALTER TABLE `purchase_debt_payments`
  ADD COLUMN `shop_account_id` BIGINT UNSIGNED NULL AFTER `purchase_id`,
  ADD INDEX `purchase_debt_payments_shop_account_id_index` (`shop_account_id`);
