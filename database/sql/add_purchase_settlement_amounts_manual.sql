-- ============================================
-- مبالغ تسویه کارت / نقد دستی در خرید (purchases)
-- معادل migration: 2026_05_20_100000_add_settlement_amounts_to_purchases_table
-- یک‌بار روی دیتابیس اجرا کنید.
-- ============================================

-- اگر ستون payment_type دارید (خرید اقساطی قبلاً اضافه شده):
ALTER TABLE `purchases`
  ADD COLUMN `card_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 AFTER `payment_type`,
  ADD COLUMN `cash_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 AFTER `card_amount`;

-- اگر خطای «ستون payment_type وجود ندارد» گرفتید، این نسخه را بزنید:
-- ALTER TABLE `purchases`
--   ADD COLUMN `card_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 AFTER `credit_earned`,
--   ADD COLUMN `cash_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 AFTER `card_amount`;

-- خریدهای قدیمی: پیش‌فرض ۰ است؛ در صورت نیاز می‌توانید کل مبلغ نقدی قدیم را پر کنید:
-- UPDATE `purchases`
-- SET `cash_amount` = `total_amount`
-- WHERE `payment_type` = 'cash'
--   AND (`card_amount` = 0 AND `cash_amount` = 0);

-- بررسی:
-- SHOW COLUMNS FROM `purchases` LIKE '%amount%';
