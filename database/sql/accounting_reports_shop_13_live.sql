-- گزارش دفتر واقعی فروشگاه ۱۳ (بدون اسناد تست)
-- اگر seed را زده‌اید، اسناد [TEST-MATRIX] از این گزارش کنار گذاشته می‌شوند.
-- خروجی را کپی کنید تا با هم وضعیت زنده را ببینیم.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;
SET @aid = 13;
SET @pat = CAST('[TEST-MATRIX]%' AS CHAR CHARSET utf8mb4) COLLATE utf8mb4_unicode_ci;

-- سود و زیان واقعی
SELECT
    ROUND(SUM(CASE WHEN a.code = '411' THEN l.credit - l.debit ELSE 0 END), 2) AS sales,
    ROUND(SUM(CASE WHEN a.code = '412' THEN l.debit - l.credit ELSE 0 END), 2) AS discounts,
    ROUND(SUM(CASE WHEN a.code = '511' THEN l.debit - l.credit ELSE 0 END), 2) AS cogs,
    ROUND(SUM(CASE WHEN a.code = '411' THEN l.credit - l.debit ELSE 0 END)
        - SUM(CASE WHEN a.code = '412' THEN l.debit - l.credit ELSE 0 END)
        - SUM(CASE WHEN a.code = '511' THEN l.debit - l.credit ELSE 0 END), 2) AS gross,
    ROUND(SUM(CASE WHEN a.code = '611' THEN l.debit - l.credit ELSE 0 END), 2) AS opex,
    ROUND(SUM(CASE WHEN a.code = '612' THEN l.debit - l.credit ELSE 0 END), 2) AS payroll,
    ROUND(SUM(CASE WHEN a.code = '613' THEN l.debit - l.credit ELSE 0 END), 2) AS loyalty,
    ROUND(SUM(CASE WHEN a.code = '431' THEN l.credit - l.debit ELSE 0 END), 2) AS other_income,
    ROUND(
        SUM(CASE WHEN a.code = '411' THEN l.credit - l.debit ELSE 0 END)
      - SUM(CASE WHEN a.code = '412' THEN l.debit - l.credit ELSE 0 END)
      - SUM(CASE WHEN a.code = '511' THEN l.debit - l.credit ELSE 0 END)
      - SUM(CASE WHEN a.code = '611' THEN l.debit - l.credit ELSE 0 END)
      - SUM(CASE WHEN a.code = '612' THEN l.debit - l.credit ELSE 0 END)
      - SUM(CASE WHEN a.code = '613' THEN l.debit - l.credit ELSE 0 END)
      + SUM(CASE WHEN a.code = '431' THEN l.credit - l.debit ELSE 0 END)
    , 2) AS net_profit
FROM accounting_lines l
JOIN accounting_vouchers v ON v.id = l.voucher_id
JOIN accounting_accounts a ON a.id = l.account_id
WHERE v.atelier_id = @aid
  AND v.status IN ('posted', 'reversed')
  AND (v.description IS NULL OR v.description NOT LIKE @pat);

-- تراز واقعی
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
  AND v.status IN ('posted', 'reversed')
  AND (v.description IS NULL OR v.description NOT LIKE @pat);

-- اسناد نامتوازن واقعی
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
  AND (v.description IS NULL OR v.description NOT LIKE @pat)
GROUP BY v.id, v.number, v.source_type, v.source_id, v.description
HAVING ABS(SUM(l.debit) - SUM(l.credit)) >= 0.02
ORDER BY v.number;

-- تعداد سند به تفکیک نوع
SELECT
    v.source_type,
    COUNT(*) AS cnt,
    SUM(v.status = 'reversed') AS reversed_cnt,
    SUM(v.reverses_voucher_id IS NOT NULL) AS storno_cnt
FROM accounting_vouchers v
WHERE v.atelier_id = @aid
  AND (v.description IS NULL OR v.description NOT LIKE @pat)
GROUP BY v.source_type
ORDER BY v.source_type;
