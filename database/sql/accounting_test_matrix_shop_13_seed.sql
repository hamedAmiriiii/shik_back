-- ماتریس تست دفتر فروشگاه ۱۳
-- فقط اسناد با توضیح [TEST-MATRIX] ساخته می‌شود. دادهٔ واقعی پاک نمی‌شود.
--
-- اجرا در phpMyAdmin: کل فایل یکجا.
-- اگر قبلاً همین تست را زده‌اید، اول همان اسناد پاک و دوباره درج می‌شوند.
--
-- بعد از اجرا: accounting_test_matrix_shop_13_report.sql
-- برای پاک کردن تست: accounting_test_matrix_shop_13_cleanup.sql
--
-- عدد انتظاری سود خالص: ۷۴۰۰۰
-- تراز آزمایشی و ترازنامه باید متوازن باشند.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;
SET @aid = 13;
SET @d = CURDATE();
SET @tag = CAST('[TEST-MATRIX]' AS CHAR CHARSET utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @pat = CAST('[TEST-MATRIX]%' AS CHAR CHARSET utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @now = NOW();

-- اگر کدینگ فروشگاه ۱۳ ناقص باشد، بقیهٔ اسکریپت را اجرا نکنید
SELECT
    a.code,
    a.id,
    a.name
FROM accounting_accounts a
WHERE a.atelier_id = @aid
  AND a.code IN (
      '11101','11111','11120','11201','11301','11302','11303',
      '11401','12101','21101','21201','311','411','412','431','511','611','612','613'
  )
ORDER BY a.code;

SELECT
    CASE
        WHEN COUNT(*) = 19 THEN 'کدینگ کامل است — ادامه دهید'
        ELSE CONCAT('کدینگ ناقص است: ', COUNT(*), ' از ۱۹ — متوقف شوید')
    END AS chart_check
FROM accounting_accounts
WHERE atelier_id = @aid
  AND code IN (
      '11101','11111','11120','11201','11301','11302','11303',
      '11401','12101','21101','21201','311','411','412','431','511','611','612','613'
  );

SET @a_11101 = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '11101' LIMIT 1);
SET @a_11111 = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '11111' LIMIT 1);
SET @a_11120 = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '11120' LIMIT 1);
SET @a_11201 = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '11201' LIMIT 1);
SET @a_11301 = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '11301' LIMIT 1);
SET @a_11302 = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '11302' LIMIT 1);
SET @a_11303 = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '11303' LIMIT 1);
SET @a_11401 = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '11401' LIMIT 1);
SET @a_12101 = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '12101' LIMIT 1);
SET @a_21101 = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '21101' LIMIT 1);
SET @a_21201 = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '21201' LIMIT 1);
SET @a_311   = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '311' LIMIT 1);
SET @a_411   = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '411' LIMIT 1);
SET @a_412   = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '412' LIMIT 1);
SET @a_431   = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '431' LIMIT 1);
SET @a_511   = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '511' LIMIT 1);
SET @a_611   = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '611' LIMIT 1);
SET @a_612   = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '612' LIMIT 1);
SET @a_613   = (SELECT id FROM accounting_accounts WHERE atelier_id = @aid AND code = '613' LIMIT 1);

-- پاک کردن اجرای قبلی همین تست
SET FOREIGN_KEY_CHECKS = 0;
DELETE l FROM accounting_lines l
INNER JOIN accounting_vouchers v ON v.id = l.voucher_id
WHERE v.atelier_id = @aid AND v.description LIKE @pat;
DELETE FROM accounting_vouchers
WHERE atelier_id = @aid AND description LIKE @pat;
SET FOREIGN_KEY_CHECKS = 1;

SET @n = (SELECT IFNULL(MAX(number), 0) FROM accounting_vouchers WHERE atelier_id = @aid);

-- ——— افتتاحیه ۱٬۰۰۰٬۰۰۰ ———
SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' افتتاحیه'), 'opening', 900001, 'posted', NULL, 'opening:900001', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11111, 1000000, 0, 'نقد حساب ۱', 1, @now, @now),
(@vid, @a_311, 0, 1000000, 'سرمایه', 2, @now, @now);

-- ——— فروش نقد کاتالوگ ۱۰۰٬۰۰۰ بها ۴۰٬۰۰۰ ———
SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' فروش نقد'), 'purchase', 900101, 'posted', NULL, 'purchase:900101', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11101, 100000, 0, 'نقد/کارت', 1, @now, @now),
(@vid, @a_411, 0, 100000, 'درآمد', 2, @now, @now),
(@vid, @a_511, 40000, 0, 'بها', 3, @now, @now),
(@vid, @a_11301, 0, 40000, 'خروج موجودی', 4, @now, @now);

-- ——— فروش نسیه ۸۰٬۰۰۰ + تسویه ———
SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' فروش نسیه'), 'purchase', 900102, 'posted', NULL, 'purchase:900102', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11201, 80000, 0, 'دریافتنی', 1, @now, @now),
(@vid, @a_411, 0, 80000, 'درآمد', 2, @now, @now),
(@vid, @a_511, 30000, 0, 'بها', 3, @now, @now),
(@vid, @a_11301, 0, 30000, 'خروج موجودی', 4, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' تسویه نسیه'), 'debt_settle', 900102, 'posted', NULL, 'debt_settle:900102', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11101, 80000, 0, 'وصول نقد', 1, @now, @now),
(@vid, @a_11201, 0, 80000, 'بستن طلب', 2, @now, @now);

-- ——— فروش چک ۵۰٬۰۰۰ + وصول ———
SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' فروش چک'), 'purchase', 900103, 'posted', NULL, 'purchase:900103', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11101, 10000, 0, 'نقد', 1, @now, @now),
(@vid, @a_11401, 40000, 0, 'چک دریافتنی', 2, @now, @now),
(@vid, @a_411, 0, 50000, 'درآمد', 3, @now, @now),
(@vid, @a_511, 20000, 0, 'بها', 4, @now, @now),
(@vid, @a_11301, 0, 20000, 'خروج موجودی', 5, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' وصول چک فروش'), 'cheque_clear', 900103, 'posted', NULL, 'cheque_clear:900103', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11101, 40000, 0, 'ورود به صندوق', 1, @now, @now),
(@vid, @a_11401, 0, 40000, 'بستن چک', 2, @now, @now);

-- ——— اقساط ۹۰٬۰۰۰ + دو قسط ———
SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' فروش اقساط'), 'purchase', 900104, 'posted', NULL, 'purchase:900104', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11101, 30000, 0, 'پیش‌پرداخت', 1, @now, @now),
(@vid, @a_11201, 60000, 0, 'مانده اقساط', 2, @now, @now),
(@vid, @a_411, 0, 90000, 'درآمد', 3, @now, @now),
(@vid, @a_511, 45000, 0, 'بها', 4, @now, @now),
(@vid, @a_11301, 0, 45000, 'خروج موجودی', 5, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' قسط ۱'), 'installment_pay', 9001041, 'posted', NULL, 'installment_pay:9001041', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11101, 30000, 0, 'وصول قسط', 1, @now, @now),
(@vid, @a_11201, 0, 30000, 'بستن طلب', 2, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' قسط ۲'), 'installment_pay', 9001042, 'posted', NULL, 'installment_pay:9001042', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11101, 30000, 0, 'وصول قسط', 1, @now, @now),
(@vid, @a_11201, 0, 30000, 'بستن طلب', 2, @now, @now);

-- ——— برگشت فروش نقد و نسیه ———
SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' برگشت فروش نقد'), 'purchase_return', 900101, 'posted', NULL, 'purchase_return:900101', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_412, 100000, 0, 'برگشت از فروش', 1, @now, @now),
(@vid, @a_11201, 0, 100000, 'برگشت به اعتبار مشتری', 2, @now, @now),
(@vid, @a_11301, 40000, 0, 'بازگشت موجودی', 3, @now, @now),
(@vid, @a_511, 0, 40000, 'برگشت بها', 4, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' برگشت فروش نسیه'), 'purchase_return', 900102, 'posted', NULL, 'purchase_return:900102', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_412, 80000, 0, 'برگشت از فروش', 1, @now, @now),
(@vid, @a_11201, 0, 80000, 'بستن طلب / اعتبار', 2, @now, @now),
(@vid, @a_11301, 30000, 0, 'بازگشت موجودی', 3, @now, @now),
(@vid, @a_511, 0, 30000, 'برگشت بها', 4, @now, @now);

-- ——— خرید مواد + تولید + فروش ساخته + برگشت ساخته ———
SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' خرید مواد خام'), 'invoice', 900201, 'posted', NULL, 'invoice:900201', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11302, 25000, 0, 'مواد اولیه', 1, @now, @now),
(@vid, @a_11120, 0, 25000, 'پرداخت از تنخواه', 2, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' تولید'), 'production', 900201, 'posted', NULL, 'production:900201', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11303, 25000, 0, 'کالای ساخته‌شده', 1, @now, @now),
(@vid, @a_11302, 0, 25000, 'مصرف مواد', 2, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' فروش محصول ساخته'), 'purchase', 900105, 'posted', NULL, 'purchase:900105', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11101, 40000, 0, 'نقد', 1, @now, @now),
(@vid, @a_411, 0, 40000, 'درآمد', 2, @now, @now),
(@vid, @a_511, 25000, 0, 'بها', 3, @now, @now),
(@vid, @a_11303, 0, 25000, 'خروج ساخته', 4, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' برگشت محصول ساخته'), 'purchase_return', 900105, 'posted', NULL, 'purchase_return:900105', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_412, 40000, 0, 'برگشت از فروش', 1, @now, @now),
(@vid, @a_11201, 0, 40000, 'برگشت به اعتبار مشتری', 2, @now, @now),
(@vid, @a_11303, 25000, 0, 'بازگشت ساخته', 3, @now, @now),
(@vid, @a_511, 0, 25000, 'برگشت بها', 4, @now, @now);

-- ——— فاکتور خرید نسیه + تسویه ———
SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' فاکتور خرید نسیه'), 'invoice', 900202, 'posted', NULL, 'invoice:900202', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11301, 15000, 0, 'خرید کالا', 1, @now, @now),
(@vid, @a_21101, 0, 15000, 'حساب پرداختنی', 2, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' تسویه فاکتور خرید'), 'document_payment', 9002021, 'posted', NULL, 'document_payment:9002021', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_21101, 15000, 0, 'بستن پرداختنی', 1, @now, @now),
(@vid, @a_11111, 0, 15000, 'پرداخت از حساب', 2, @now, @now);

-- ——— فاکتور خرید چک + وصول ———
SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' فاکتور خرید چک'), 'invoice', 900203, 'posted', NULL, 'invoice:900203', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11301, 8000, 0, 'خرید کالا', 1, @now, @now),
(@vid, @a_21201, 0, 8000, 'چک پرداختنی', 2, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' وصول چک خرید'), 'cheque_clear', 900203, 'posted', NULL, 'cheque_clear:900203', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_21201, 8000, 0, 'بستن چک پرداختنی', 1, @now, @now),
(@vid, @a_11111, 0, 8000, 'خروج از حساب', 2, @now, @now);

-- ——— برگشت فاکتور خرید نقد (storno) ———
SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' فاکتور خرید نقد برای برگشت'), 'invoice', 900204, 'posted', NULL, 'invoice:900204', @now, @now);
SET @vid_inv204 = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid_inv204, @a_11301, 9000, 0, 'خرید کالا', 1, @now, @now),
(@vid_inv204, @a_11111, 0, 9000, 'پرداخت نقد', 2, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' برگشت فاکتور خرید نقد'), 'invoice', 900204, 'posted', @vid_inv204, NULL, @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11301, 0, 9000, 'خرید کالا', 1, @now, @now),
(@vid, @a_11111, 9000, 0, 'پرداخت نقد', 2, @now, @now);
UPDATE accounting_vouchers
SET status = 'reversed', active_source_key = NULL, updated_at = @now
WHERE id = @vid_inv204;

-- ——— هزینه جاری + سرمایه + حقوق + مساعده + برگشت هزینه ———
SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' هزینه جاری'), 'expense', 900301, 'posted', NULL, 'expense:900301', @now, @now);
SET @vid_exp301 = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid_exp301, @a_611, 5000, 0, 'هزینه جاری', 1, @now, @now),
(@vid_exp301, @a_11120, 0, 5000, 'پرداخت از تنخواه', 2, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' برگشت هزینه جاری'), 'expense', 900301, 'posted', @vid_exp301, NULL, @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_611, 0, 5000, 'هزینه جاری', 1, @now, @now),
(@vid, @a_11120, 5000, 0, 'پرداخت از تنخواه', 2, @now, @now);
UPDATE accounting_vouchers
SET status = 'reversed', active_source_key = NULL, updated_at = @now
WHERE id = @vid_exp301;

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' هزینه سرمایه'), 'expense', 900302, 'posted', NULL, 'expense:900302', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_12101, 20000, 0, 'هزینه سرمایه', 1, @now, @now),
(@vid, @a_11111, 0, 20000, 'پرداخت از حساب', 2, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' پرداخت حقوق'), 'expense', 900303, 'posted', NULL, 'expense:900303', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_612, 12000, 0, 'پرداخت حقوق', 1, @now, @now),
(@vid, @a_11111, 0, 12000, 'پرداخت از حساب', 2, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' مساعده'), 'expense', 900304, 'posted', NULL, 'expense:900304', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_612, 3000, 0, 'مساعده', 1, @now, @now),
(@vid, @a_11111, 0, 3000, 'پرداخت از حساب', 2, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' اعطای اعتبار دستی'), 'expense', 900305, 'posted', NULL, 'expense:900305', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_613, 2000, 0, 'اعطای اعتبار دستی', 1, @now, @now),
(@vid, @a_11201, 0, 2000, 'بدهی به مشتری', 2, @now, @now);

-- ——— فروش با اعتبار وفاداری ———
SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' فروش با اعتبار'), 'purchase', 900106, 'posted', NULL, 'purchase:900106', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11101, 15000, 0, 'نقد', 1, @now, @now),
(@vid, @a_613, 5000, 0, 'اعتبار', 2, @now, @now),
(@vid, @a_411, 0, 20000, 'درآمد', 3, @now, @now),
(@vid, @a_511, 8000, 0, 'بها', 4, @now, @now),
(@vid, @a_11301, 0, 8000, 'خروج موجودی', 5, @now, @now);

-- ——— چک متفرقه + خرید/فروش دستی + تنخواه + تطبیق ———
SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' چک دریافتنی متفرقه'), 'income', 900401, 'posted', NULL, 'income:900401', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11401, 7000, 0, 'چک دریافتنی', 1, @now, @now),
(@vid, @a_431, 0, 7000, 'درآمد متفرقه', 2, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' وصول چک متفرقه'), 'cheque_clear', 900401, 'posted', NULL, 'cheque_clear:900401', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11101, 7000, 0, 'ورود به صندوق', 1, @now, @now),
(@vid, @a_11401, 0, 7000, 'بستن چک', 2, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' خرید دستی'), 'manual_trade', 900501, 'posted', NULL, 'manual_trade:900501', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_611, 4000, 0, 'خرید دستی', 1, @now, @now),
(@vid, @a_11111, 0, 4000, 'پرداخت از حساب', 2, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' فروش دستی'), 'manual_trade', 900502, 'posted', NULL, 'manual_trade:900502', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11111, 6000, 0, 'ورود به حساب', 1, @now, @now),
(@vid, @a_431, 0, 6000, 'فروش دستی', 2, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' شارژ تنخواه'), 'account_transfer', 900001, 'posted', NULL, 'account_transfer:900001', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11120, 50000, 0, 'شارژ تنخواه', 1, @now, @now),
(@vid, @a_11111, 0, 50000, 'خروج از حساب ۱', 2, @now, @now);

SET @n = @n + 1;
INSERT INTO accounting_vouchers
    (atelier_id, number, date, description, source_type, source_id, status, reverses_voucher_id, active_source_key, created_at, updated_at)
VALUES
    (@aid, @n, @d, CONCAT(@tag, ' تطبیق روزانه'), 'recon_deposit', 900001, 'posted', NULL, 'recon_deposit:900001', @now, @now);
SET @vid = LAST_INSERT_ID();
INSERT INTO accounting_lines (voucher_id, account_id, debit, credit, description, sort_order, created_at, updated_at) VALUES
(@vid, @a_11111, 382000, 0, 'واریز به حساب فروشگاه', 1, @now, @now),
(@vid, @a_11101, 0, 382000, 'خروج از صندوق فروش', 2, @now, @now);

-- کنترل درج
SELECT
    COUNT(*) AS vouchers_inserted,
    SUM(status = 'posted' AND reverses_voucher_id IS NULL) AS posted,
    SUM(status = 'reversed') AS reversed_originals,
    SUM(reverses_voucher_id IS NOT NULL) AS storno
FROM accounting_vouchers
WHERE atelier_id = @aid AND description LIKE @pat;
