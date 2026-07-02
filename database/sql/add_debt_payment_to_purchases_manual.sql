-- حالت پرداخت قرضی (نسیه) برای فاکتورهای فروشگاه

ALTER TABLE `purchases`
  MODIFY `payment_type` ENUM('cash', 'installment', 'debt') NOT NULL DEFAULT 'cash';

ALTER TABLE `purchases`
  ADD COLUMN `is_debt_settled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `cash_amount`,
  ADD COLUMN `debt_settled_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_debt_settled`,
  ADD COLUMN `debt_settled_card_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `debt_settled_at`,
  ADD COLUMN `debt_settled_cash_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `debt_settled_card_amount`,
  ADD COLUMN `debt_settlement_note` VARCHAR(500) NULL DEFAULT NULL AFTER `debt_settled_cash_amount`;
