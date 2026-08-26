-- فقط انتقال مجدد داده‌های قدیمی حساب ۱ و ۲ به جدول جدید
-- (اگر قبلاً جداول ساخته شده‌اند ولی موجودی ۰ است، همین را اجرا کنید)
-- deposit_record_id فقط وقتی رکورد واریز واقعاً وجود دارد ست می‌شود تا FK نشکند.

-- اطمینان از وجود حساب‌های پیش‌فرض
INSERT INTO `shop_accounts` (`atelier_id`, `name`, `sort_order`, `legacy_slot`, `is_active`, `created_at`, `updated_at`)
SELECT a.id, 'حساب ۱', 1, 'account_1', 1, NOW(), NOW()
FROM `ateliers` a
WHERE NOT EXISTS (
  SELECT 1 FROM `shop_accounts` sa
  WHERE sa.atelier_id = a.id AND sa.legacy_slot = 'account_1'
);

INSERT INTO `shop_accounts` (`atelier_id`, `name`, `sort_order`, `legacy_slot`, `is_active`, `created_at`, `updated_at`)
SELECT a.id, 'حساب ۲', 2, 'account_2', 1, NOW(), NOW()
FROM `ateliers` a
WHERE NOT EXISTS (
  SELECT 1 FROM `shop_accounts` sa
  WHERE sa.atelier_id = a.id AND sa.legacy_slot = 'account_2'
);

-- درج ردیف‌های مفقود حساب ۱
INSERT INTO `daily_shop_reconciliation_account_deposits`
  (`reconciliation_id`, `shop_account_id`, `amount`, `deposit_record_id`, `created_at`, `updated_at`)
SELECT
  r.id,
  sa.id,
  r.deposit_account_1,
  CASE
    WHEN r.deposit_record_account_1_id IS NOT NULL
      AND EXISTS (
        SELECT 1 FROM `daily_shop_reconciliation_deposits` d
        WHERE d.id = r.deposit_record_account_1_id
      )
    THEN r.deposit_record_account_1_id
    ELSE NULL
  END,
  NOW(),
  NOW()
FROM `daily_shop_reconciliations` r
INNER JOIN `shop_accounts` sa
  ON sa.atelier_id = r.atelier_id AND sa.legacy_slot = 'account_1'
WHERE (r.deposit_account_1 > 0 OR r.deposit_record_account_1_id IS NOT NULL)
  AND NOT EXISTS (
    SELECT 1 FROM `daily_shop_reconciliation_account_deposits` d
    WHERE d.reconciliation_id = r.id AND d.shop_account_id = sa.id
  );

-- درج ردیف‌های مفقود حساب ۲
INSERT INTO `daily_shop_reconciliation_account_deposits`
  (`reconciliation_id`, `shop_account_id`, `amount`, `deposit_record_id`, `created_at`, `updated_at`)
SELECT
  r.id,
  sa.id,
  r.deposit_account_2,
  CASE
    WHEN r.deposit_record_account_2_id IS NOT NULL
      AND EXISTS (
        SELECT 1 FROM `daily_shop_reconciliation_deposits` d
        WHERE d.id = r.deposit_record_account_2_id
      )
    THEN r.deposit_record_account_2_id
    ELSE NULL
  END,
  NOW(),
  NOW()
FROM `daily_shop_reconciliations` r
INNER JOIN `shop_accounts` sa
  ON sa.atelier_id = r.atelier_id AND sa.legacy_slot = 'account_2'
WHERE (r.deposit_account_2 > 0 OR r.deposit_record_account_2_id IS NOT NULL)
  AND NOT EXISTS (
    SELECT 1 FROM `daily_shop_reconciliation_account_deposits` d
    WHERE d.reconciliation_id = r.id AND d.shop_account_id = sa.id
  );

-- اصلاح ردیف‌هایی که با مبلغ ۰ ساخته شده‌اند
UPDATE `daily_shop_reconciliation_account_deposits` d
INNER JOIN `shop_accounts` sa ON sa.id = d.shop_account_id AND sa.legacy_slot = 'account_1'
INNER JOIN `daily_shop_reconciliations` r ON r.id = d.reconciliation_id
SET d.amount = r.deposit_account_1,
    d.updated_at = NOW()
WHERE d.amount <= 0 AND r.deposit_account_1 > 0;

UPDATE `daily_shop_reconciliation_account_deposits` d
INNER JOIN `shop_accounts` sa ON sa.id = d.shop_account_id AND sa.legacy_slot = 'account_2'
INNER JOIN `daily_shop_reconciliations` r ON r.id = d.reconciliation_id
SET d.amount = r.deposit_account_2,
    d.updated_at = NOW()
WHERE d.amount <= 0 AND r.deposit_account_2 > 0;

-- اتصال shop_account_id روی رکورد واریز (در صورت وجود ستون)
UPDATE `daily_shop_reconciliation_deposits` dep
INNER JOIN `daily_shop_reconciliation_account_deposits` ad ON ad.deposit_record_id = dep.id
SET dep.shop_account_id = ad.shop_account_id
WHERE dep.shop_account_id IS NULL;
