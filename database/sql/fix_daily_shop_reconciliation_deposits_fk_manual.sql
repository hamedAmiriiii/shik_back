-- ============================================================
-- اصلاح FK: deposit_record_* → daily_shop_reconciliation_deposits
-- اگر در phpMyAdmin روی id کلیک می‌کنید و به جدول جدید نمی‌رود،
-- معمولاً FK تعریف نشده یا id در جدول deposits وجود ندارد.
-- ============================================================

-- 1) بررسی: idهایی که در reconciliations هست ولی در deposits نیست
SELECT 'orphan_account_1' AS slot, r.id AS reconciliation_id, r.deposit_record_account_1_id AS missing_deposit_id
FROM daily_shop_reconciliations r
LEFT JOIN daily_shop_reconciliation_deposits d ON d.id = r.deposit_record_account_1_id
WHERE r.deposit_record_account_1_id IS NOT NULL AND d.id IS NULL

UNION ALL

SELECT 'orphan_account_2', r.id, r.deposit_record_account_2_id
FROM daily_shop_reconciliations r
LEFT JOIN daily_shop_reconciliation_deposits d ON d.id = r.deposit_record_account_2_id
WHERE r.deposit_record_account_2_id IS NOT NULL AND d.id IS NULL

UNION ALL

SELECT 'orphan_cash', r.id, r.deposit_record_cash_id
FROM daily_shop_reconciliations r
LEFT JOIN daily_shop_reconciliation_deposits d ON d.id = r.deposit_record_cash_id
WHERE r.deposit_record_cash_id IS NOT NULL AND d.id IS NULL;

-- 2) اگر هنوز در invoices هستند، کپی به deposits (با همان id)
INSERT IGNORE INTO daily_shop_reconciliation_deposits
  (id, atelier_id, amount, title, description, date, user_name, created_at, updated_at)
SELECT
  i.id, i.atelier_id, i.amount, i.title, i.description, i.date, i.user_name, i.created_at, i.updated_at
FROM invoices i
WHERE i.id IN (
  SELECT deposit_record_account_1_id FROM daily_shop_reconciliations WHERE deposit_record_account_1_id IS NOT NULL
  UNION
  SELECT deposit_record_account_2_id FROM daily_shop_reconciliations WHERE deposit_record_account_2_id IS NOT NULL
  UNION
  SELECT deposit_record_cash_id FROM daily_shop_reconciliations WHERE deposit_record_cash_id IS NOT NULL
);

-- 3) حذف FKهای قدیمی (اگر وجود دارند — خطا داد نادیده بگیر)
-- ALTER TABLE daily_shop_reconciliations DROP FOREIGN KEY daily_shop_reconciliations_invoice_account_1_id_foreign;
-- ALTER TABLE daily_shop_reconciliations DROP FOREIGN KEY daily_shop_reconciliations_invoice_account_2_id_foreign;
-- ALTER TABLE daily_shop_reconciliations DROP FOREIGN KEY daily_shop_reconciliations_invoice_cash_id_foreign;

-- 4) حذف FKهای جدید (اگر قبلاً ناقص اضافه شده — برای اضافه مجدد)
-- ALTER TABLE daily_shop_reconciliations DROP FOREIGN KEY daily_shop_reconciliations_deposit_record_account_1_id_foreign;
-- ALTER TABLE daily_shop_reconciliations DROP FOREIGN KEY daily_shop_reconciliations_deposit_record_account_2_id_foreign;
-- ALTER TABLE daily_shop_reconciliations DROP FOREIGN KEY daily_shop_reconciliations_deposit_record_cash_id_foreign;

-- 5) اضافه کردن FK به جدول جدید (این باعث لینک در phpMyAdmin می‌شود)
ALTER TABLE daily_shop_reconciliations
  ADD CONSTRAINT daily_shop_reconciliations_deposit_record_account_1_id_foreign
    FOREIGN KEY (deposit_record_account_1_id) REFERENCES daily_shop_reconciliation_deposits (id) ON DELETE SET NULL,
  ADD CONSTRAINT daily_shop_reconciliations_deposit_record_account_2_id_foreign
    FOREIGN KEY (deposit_record_account_2_id) REFERENCES daily_shop_reconciliation_deposits (id) ON DELETE SET NULL,
  ADD CONSTRAINT daily_shop_reconciliations_deposit_record_cash_id_foreign
    FOREIGN KEY (deposit_record_cash_id) REFERENCES daily_shop_reconciliation_deposits (id) ON DELETE SET NULL;

-- 6) بعد از اطمینان از انتقال، حذف از invoices
-- DELETE FROM invoices
-- WHERE id IN (
--   SELECT deposit_record_account_1_id FROM daily_shop_reconciliations WHERE deposit_record_account_1_id IS NOT NULL
--   UNION
--   SELECT deposit_record_account_2_id FROM daily_shop_reconciliations WHERE deposit_record_account_2_id IS NOT NULL
--   UNION
--   SELECT deposit_record_cash_id FROM daily_shop_reconciliations WHERE deposit_record_cash_id IS NOT NULL
-- );
