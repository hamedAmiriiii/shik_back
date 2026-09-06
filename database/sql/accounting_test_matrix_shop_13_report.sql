-- گزارش ماتریس تست فروشگاه ۱۳
-- فقط اسناد [TEST-MATRIX] — دادهٔ واقعی قاطی نمی‌شود.
-- بعد از seed اجرا کنید. چهار جدول زیر را کپی کنید و اینجا بفرستید:
--   ۱) سود و زیان
--   ۲) تراز
--   ۳) ماندهٔ حساب‌ها
--   ۴) اسناد نامتوازن (باید صفر ردیف باشد)

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;
SET @aid = 13;
SET @tag = CAST('[TEST-MATRIX]' AS CHAR CHARSET utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @pat = CAST('[TEST-MATRIX]%' AS CHAR CHARSET utf8mb4) COLLATE utf8mb4_unicode_ci;

-- ۱) سود و زیان + عدد انتظاری
SELECT
    ROUND(SUM(CASE WHEN a.code = '411' THEN l.credit - l.debit ELSE 0 END), 2) AS sales,
    380000 AS sales_expected,
    ROUND(SUM(CASE WHEN a.code = '412' THEN l.debit - l.credit ELSE 0 END), 2) AS discounts,
    220000 AS discounts_expected,
    ROUND(SUM(CASE WHEN a.code = '511' THEN l.debit - l.credit ELSE 0 END), 2) AS cogs,
    73000 AS cogs_expected,
    ROUND(SUM(CASE WHEN a.code = '411' THEN l.credit - l.debit ELSE 0 END)
        - SUM(CASE WHEN a.code = '412' THEN l.debit - l.credit ELSE 0 END)
        - SUM(CASE WHEN a.code = '511' THEN l.debit - l.credit ELSE 0 END), 2) AS gross,
    87000 AS gross_expected,
    ROUND(SUM(CASE WHEN a.code = '611' THEN l.debit - l.credit ELSE 0 END), 2) AS opex,
    4000 AS opex_expected,
    ROUND(SUM(CASE WHEN a.code = '612' THEN l.debit - l.credit ELSE 0 END), 2) AS payroll,
    15000 AS payroll_expected,
    ROUND(SUM(CASE WHEN a.code = '613' THEN l.debit - l.credit ELSE 0 END), 2) AS loyalty,
    7000 AS loyalty_expected,
    ROUND(SUM(CASE WHEN a.code = '431' THEN l.credit - l.debit ELSE 0 END), 2) AS other_income,
    13000 AS other_expected,
    ROUND(
        SUM(CASE WHEN a.code = '411' THEN l.credit - l.debit ELSE 0 END)
      - SUM(CASE WHEN a.code = '412' THEN l.debit - l.credit ELSE 0 END)
      - SUM(CASE WHEN a.code = '511' THEN l.debit - l.credit ELSE 0 END)
      - SUM(CASE WHEN a.code = '611' THEN l.debit - l.credit ELSE 0 END)
      - SUM(CASE WHEN a.code = '612' THEN l.debit - l.credit ELSE 0 END)
      - SUM(CASE WHEN a.code = '613' THEN l.debit - l.credit ELSE 0 END)
      + SUM(CASE WHEN a.code = '431' THEN l.credit - l.debit ELSE 0 END)
    , 2) AS net_profit,
    74000 AS net_expected,
    CASE
        WHEN ABS(
            SUM(CASE WHEN a.code = '411' THEN l.credit - l.debit ELSE 0 END)
          - SUM(CASE WHEN a.code = '412' THEN l.debit - l.credit ELSE 0 END)
          - SUM(CASE WHEN a.code = '511' THEN l.debit - l.credit ELSE 0 END)
          - SUM(CASE WHEN a.code = '611' THEN l.debit - l.credit ELSE 0 END)
          - SUM(CASE WHEN a.code = '612' THEN l.debit - l.credit ELSE 0 END)
          - SUM(CASE WHEN a.code = '613' THEN l.debit - l.credit ELSE 0 END)
          + SUM(CASE WHEN a.code = '431' THEN l.credit - l.debit ELSE 0 END)
          - 74000
        ) < 0.02 THEN 'قبول'
        ELSE 'رد — با ۷۴۰۰۰ فرق دارد'
    END AS pnl_check
FROM accounting_lines l
JOIN accounting_vouchers v ON v.id = l.voucher_id
JOIN accounting_accounts a ON a.id = l.account_id
WHERE v.atelier_id = @aid
  AND v.description LIKE @pat
  AND v.status IN ('posted', 'reversed');

-- ۲) تراز آزمایشی و معادلهٔ ترازنامه
SELECT
    ROUND(SUM(l.debit), 2) AS turnover_debit,
    ROUND(SUM(l.credit), 2) AS turnover_credit,
    CASE WHEN ABS(SUM(l.debit) - SUM(l.credit)) < 0.02 THEN 'متوازن' ELSE 'نامتوازن' END AS trial_check,
    ROUND(SUM(CASE WHEN a.kind = 'asset'
        THEN CASE WHEN a.nature = 'credit' THEN l.credit - l.debit ELSE l.debit - l.credit END
        ELSE 0 END), 2) AS assets,
    ROUND(SUM(CASE WHEN a.kind = 'liability'
        THEN CASE WHEN a.nature = 'credit' THEN l.credit - l.debit ELSE l.debit - l.credit END
        ELSE 0 END), 2) AS liabilities,
    ROUND(SUM(CASE WHEN a.kind = 'equity'
        THEN CASE WHEN a.nature = 'credit' THEN l.credit - l.debit ELSE l.debit - l.credit END
        ELSE 0 END), 2) AS equity,
    ROUND(
        SUM(CASE WHEN a.code = '411' THEN l.credit - l.debit ELSE 0 END)
      - SUM(CASE WHEN a.code = '412' THEN l.debit - l.credit ELSE 0 END)
      - SUM(CASE WHEN a.code = '511' THEN l.debit - l.credit ELSE 0 END)
      - SUM(CASE WHEN a.code = '611' THEN l.debit - l.credit ELSE 0 END)
      - SUM(CASE WHEN a.code = '612' THEN l.debit - l.credit ELSE 0 END)
      - SUM(CASE WHEN a.code = '613' THEN l.debit - l.credit ELSE 0 END)
      + SUM(CASE WHEN a.code = '431' THEN l.credit - l.debit ELSE 0 END)
    , 2) AS current_profit,
    ROUND(
        SUM(CASE WHEN a.kind = 'liability'
            THEN CASE WHEN a.nature = 'credit' THEN l.credit - l.debit ELSE l.debit - l.credit END
            ELSE 0 END)
      + SUM(CASE WHEN a.kind = 'equity'
            THEN CASE WHEN a.nature = 'credit' THEN l.credit - l.debit ELSE l.debit - l.credit END
            ELSE 0 END)
      + (
            SUM(CASE WHEN a.code = '411' THEN l.credit - l.debit ELSE 0 END)
          - SUM(CASE WHEN a.code = '412' THEN l.debit - l.credit ELSE 0 END)
          - SUM(CASE WHEN a.code = '511' THEN l.debit - l.credit ELSE 0 END)
          - SUM(CASE WHEN a.code = '611' THEN l.debit - l.credit ELSE 0 END)
          - SUM(CASE WHEN a.code = '612' THEN l.debit - l.credit ELSE 0 END)
          - SUM(CASE WHEN a.code = '613' THEN l.debit - l.credit ELSE 0 END)
          + SUM(CASE WHEN a.code = '431' THEN l.credit - l.debit ELSE 0 END)
        )
    , 2) AS liabilities_equity_profit,
    CASE WHEN ABS(
        SUM(CASE WHEN a.kind = 'asset'
            THEN CASE WHEN a.nature = 'credit' THEN l.credit - l.debit ELSE l.debit - l.credit END
            ELSE 0 END)
      - (
            SUM(CASE WHEN a.kind = 'liability'
                THEN CASE WHEN a.nature = 'credit' THEN l.credit - l.debit ELSE l.debit - l.credit END
                ELSE 0 END)
          + SUM(CASE WHEN a.kind = 'equity'
                THEN CASE WHEN a.nature = 'credit' THEN l.credit - l.debit ELSE l.debit - l.credit END
                ELSE 0 END)
          + (
                SUM(CASE WHEN a.code = '411' THEN l.credit - l.debit ELSE 0 END)
              - SUM(CASE WHEN a.code = '412' THEN l.debit - l.credit ELSE 0 END)
              - SUM(CASE WHEN a.code = '511' THEN l.debit - l.credit ELSE 0 END)
              - SUM(CASE WHEN a.code = '611' THEN l.debit - l.credit ELSE 0 END)
              - SUM(CASE WHEN a.code = '612' THEN l.debit - l.credit ELSE 0 END)
              - SUM(CASE WHEN a.code = '613' THEN l.debit - l.credit ELSE 0 END)
              + SUM(CASE WHEN a.code = '431' THEN l.credit - l.debit ELSE 0 END)
            )
        )
    ) < 0.02 THEN 'ترازنامه برقرار' ELSE 'ترازنامه رد' END AS balance_sheet_check
FROM accounting_lines l
JOIN accounting_vouchers v ON v.id = l.voucher_id
JOIN accounting_accounts a ON a.id = l.account_id
WHERE v.atelier_id = @aid
  AND v.description LIKE @pat
  AND v.status IN ('posted', 'reversed');

-- ۳) ماندهٔ هر حساب (برای کنترل دستی)
SELECT
    a.code,
    a.name,
    a.kind,
    a.nature,
    ROUND(SUM(l.debit), 2) AS debit_turn,
    ROUND(SUM(l.credit), 2) AS credit_turn,
    ROUND(CASE WHEN a.nature = 'credit' THEN SUM(l.credit) - SUM(l.debit) ELSE SUM(l.debit) - SUM(l.credit) END, 2) AS signed_balance
FROM accounting_lines l
JOIN accounting_vouchers v ON v.id = l.voucher_id
JOIN accounting_accounts a ON a.id = l.account_id
WHERE v.atelier_id = @aid
  AND v.description LIKE @pat
  AND v.status IN ('posted', 'reversed')
GROUP BY a.id, a.code, a.name, a.kind, a.nature
HAVING ABS(SUM(l.debit)) >= 0.01 OR ABS(SUM(l.credit)) >= 0.01
ORDER BY a.code;

-- ۴) اسناد نامتوازن — باید خالی باشد
SELECT
    v.id,
    v.number,
    v.source_type,
    v.source_id,
    v.description,
    ROUND(SUM(l.debit), 2) AS debit,
    ROUND(SUM(l.credit), 2) AS credit
FROM accounting_vouchers v
JOIN accounting_lines l ON l.voucher_id = v.id
WHERE v.atelier_id = @aid
  AND v.description LIKE @pat
GROUP BY v.id, v.number, v.source_type, v.source_id, v.description
HAVING ABS(SUM(l.debit) - SUM(l.credit)) >= 0.02
ORDER BY v.number;

-- ۵) فهرست اسناد تست
SELECT
    v.number,
    v.source_type,
    v.source_id,
    v.status,
    CASE WHEN v.reverses_voucher_id IS NULL THEN 0 ELSE 1 END AS is_storno,
    v.description,
    ROUND((SELECT SUM(x.debit) FROM accounting_lines x WHERE x.voucher_id = v.id), 2) AS amount
FROM accounting_vouchers v
WHERE v.atelier_id = @aid
  AND v.description LIKE @pat
ORDER BY v.number;
