-- وصل هزینه به حساب درخت حسابداری
-- معادل migration 2026_10_17_000001_add_accounting_account_id_to_expenses
-- اگر Duplicate column / Duplicate key آمد همان را رد کنید.

ALTER TABLE `expenses`
    ADD COLUMN `accounting_account_id` BIGINT UNSIGNED NULL AFTER `atelier_id`;

ALTER TABLE `expenses`
    ADD INDEX `expenses_accounting_account_id_index` (`accounting_account_id`);
