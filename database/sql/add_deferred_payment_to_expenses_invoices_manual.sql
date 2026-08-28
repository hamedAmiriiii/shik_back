-- پرداخت هزینه/فاکتور: نقد از حساب، چکی، نسیه
-- معادل migration 2026_08_27_130000
-- اگر ستونی از قبل هست همان ALTER را رد کنید.
-- payment_method: account | cheque | credit
-- payment_status: paid | unpaid
-- فقط ردیف‌های paid از موجودی حساب کم می‌شوند.

ALTER TABLE `expenses`
    ADD COLUMN `payment_method` VARCHAR(16) NOT NULL DEFAULT 'account' AFTER `shop_account_id`,
    ADD COLUMN `payment_status` VARCHAR(16) NOT NULL DEFAULT 'paid' AFTER `payment_method`,
    ADD COLUMN `paid_at` TIMESTAMP NULL AFTER `payment_status`,
    ADD COLUMN `cheque_id` BIGINT UNSIGNED NULL AFTER `paid_at`;

ALTER TABLE `invoices`
    ADD COLUMN `payment_method` VARCHAR(16) NOT NULL DEFAULT 'account' AFTER `shop_account_id`,
    ADD COLUMN `payment_status` VARCHAR(16) NOT NULL DEFAULT 'paid' AFTER `payment_method`,
    ADD COLUMN `paid_at` TIMESTAMP NULL AFTER `payment_status`,
    ADD COLUMN `cheque_id` BIGINT UNSIGNED NULL AFTER `paid_at`;

ALTER TABLE `cheques`
    ADD COLUMN `invoice_id` BIGINT UNSIGNED NULL AFTER `expense_id`,
    ADD COLUMN `shop_account_id` BIGINT UNSIGNED NULL AFTER `invoice_id`;

UPDATE `expenses` SET `payment_method` = 'account', `payment_status` = 'paid' WHERE `shop_account_id` IS NOT NULL;
UPDATE `invoices` SET `payment_method` = 'account', `payment_status` = 'paid' WHERE `shop_account_id` IS NOT NULL;
