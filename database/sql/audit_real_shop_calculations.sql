-- ممیزی فروشگاه واقعی: محاسبه سیستم در برابر ورود کاربر
-- کل فایل را در phpMyAdmin اجرا کنید و خروجی همه جدول‌ها را کپی کنید.
--
-- مرحله ۱: فقط بلوک «لیست فروشگاه‌ها» را اجرا کنید و atelier_id را بردارید.
-- مرحله ۲: همان عدد را در SET @aid بگذارید و بقیه را اجرا کنید.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

-- ========== ۰) لیست فروشگاه‌های فعال ==========
SELECT
    a.id AS atelier_id,
    a.name,
    a.project_type,
    a.subscription_status,
    COUNT(p.id) AS sales_count,
    ROUND(IFNULL(SUM(p.total_amount), 0), 0) AS sales_sum,
    MIN(p.created_at) AS first_sale,
    MAX(p.created_at) AS last_sale
FROM ateliers a
LEFT JOIN purchases p ON p.atelier_id = a.id
GROUP BY a.id, a.name, a.project_type, a.subscription_status
HAVING sales_count > 0
ORDER BY sales_count DESC;

-- ========== شناسه فروشگاه را اینجا بگذارید ==========
SET @aid = 0; -- <-- این عدد را عوض کنید

-- ========== ۱) خلاصه پرچم‌ها (یک ردیف) ==========
SELECT
    @aid AS atelier_id,
    (SELECT name FROM ateliers WHERE id = @aid) AS shop_name,
    (SELECT COUNT(*) FROM purchases WHERE atelier_id = @aid) AS sales_count,
    (SELECT ROUND(SUM(total_amount), 0) FROM purchases WHERE atelier_id = @aid) AS sales_sum,
    (SELECT COUNT(*) FROM purchased_products pp JOIN purchases p ON p.id = pp.purchase_id WHERE p.atelier_id = @aid) AS line_count,
    (SELECT COUNT(*) FROM purchase_item_returns WHERE atelier_id = @aid) AS return_count,
    (SELECT COUNT(*) FROM user_shiksho WHERE atelier_id = @aid) AS customer_count,
    (SELECT COUNT(*) FROM products WHERE atelier_id = @aid AND deleted_at IS NULL) AS product_count
FROM DUAL;

-- ========== ۲) فاکتورهایی که جمع اقلام با مبلغ فاکتور نمی‌خواند (باگ سیستم / ویرایش دستی) ==========
SELECT
    p.id AS purchase_id,
    p.payment_type,
    p.phone,
    ROUND(p.total_amount, 0) AS total_amount,
    ROUND(IFNULL(p.discount_amount, 0), 0) AS discount_amount,
    ROUND(IFNULL(SUM(pp.quantity * pp.sale_price), 0), 0) AS lines_sum,
    ROUND(p.total_amount - IFNULL(SUM(pp.quantity * pp.sale_price), 0), 0) AS gap_lines,
    p.created_at
FROM purchases p
LEFT JOIN purchased_products pp ON pp.purchase_id = p.id
WHERE p.atelier_id = @aid
GROUP BY p.id, p.payment_type, p.phone, p.total_amount, p.discount_amount, p.created_at
HAVING ABS(p.total_amount - IFNULL(SUM(pp.quantity * pp.sale_price), 0)) >= 1000
   AND IFNULL(p.payment_type, 'cash') <> 'installment'
ORDER BY ABS(p.total_amount - IFNULL(SUM(pp.quantity * pp.sale_price), 0)) DESC
LIMIT 40;

-- ========== ۳) تسویه نقد/کارت/اعتبار/تخفیف با مبلغ فاکتور نمی‌خواند ==========
SELECT
    p.id AS purchase_id,
    p.payment_type,
    p.phone,
    ROUND(p.total_amount, 0) AS total_amount,
    ROUND(IFNULL(p.discount_amount, 0), 0) AS discount_amount,
    ROUND(IFNULL(p.credit_used, 0), 0) AS credit_used,
    ROUND(IFNULL(p.card_amount, 0), 0) AS card_amount,
    ROUND(IFNULL(p.cash_amount, 0), 0) AS cash_amount,
    ROUND(IFNULL(ch.amount, 0), 0) AS cheque_amount,
    ROUND(
        p.total_amount
        - IFNULL(p.discount_amount, 0)
        - IFNULL(p.credit_used, 0)
        - IFNULL(p.card_amount, 0)
        - IFNULL(p.cash_amount, 0)
        - IFNULL(ch.amount, 0)
        - IF(p.payment_type = 'debt' AND IFNULL(p.is_debt_settled, 0) = 0,
             p.total_amount - IFNULL(p.discount_amount, 0) - IFNULL(p.credit_used, 0)
             - IFNULL(p.card_amount, 0) - IFNULL(p.cash_amount, 0) - IFNULL(ch.amount, 0),
             0)
    , 0) AS settlement_gap,
    p.created_at
FROM purchases p
LEFT JOIN cheques ch ON ch.id = p.cheque_id
WHERE p.atelier_id = @aid
  AND IFNULL(p.payment_type, 'cash') NOT IN ('installment')
HAVING ABS(settlement_gap) >= 1000
ORDER BY ABS(settlement_gap) DESC
LIMIT 40;

-- ========== ۴) نشانه‌های ورود اشتباه کاربر ==========
SELECT
    'sale_price_zero' AS issue,
    COUNT(*) AS cnt
FROM purchased_products pp
JOIN purchases p ON p.id = pp.purchase_id
WHERE p.atelier_id = @aid AND IFNULL(pp.sale_price, 0) = 0
UNION ALL
SELECT 'purchase_price_zero', COUNT(*)
FROM purchased_products pp
JOIN purchases p ON p.id = pp.purchase_id
WHERE p.atelier_id = @aid AND IFNULL(pp.purchase_price, 0) = 0
UNION ALL
SELECT 'sold_below_cost', COUNT(*)
FROM purchased_products pp
JOIN purchases p ON p.id = pp.purchase_id
WHERE p.atelier_id = @aid
  AND pp.sale_price > 0
  AND pp.purchase_price > pp.sale_price
UNION ALL
SELECT 'sale_without_phone', COUNT(*)
FROM purchases p
WHERE p.atelier_id = @aid AND (p.phone IS NULL OR p.phone = '')
UNION ALL
SELECT 'discount_gt_total', COUNT(*)
FROM purchases p
WHERE p.atelier_id = @aid AND IFNULL(p.discount_amount, 0) > p.total_amount + 1
UNION ALL
SELECT 'credit_used_gt_total', COUNT(*)
FROM purchases p
WHERE p.atelier_id = @aid AND IFNULL(p.credit_used, 0) > p.total_amount + 1
UNION ALL
SELECT 'credit_earned_with_discount', COUNT(*)
FROM purchases p
WHERE p.atelier_id = @aid
  AND IFNULL(p.discount_amount, 0) > 0
  AND IFNULL(p.credit_earned, 0) > 0
UNION ALL
SELECT 'qty_zero_or_negative', COUNT(*)
FROM purchased_products pp
JOIN purchases p ON p.id = pp.purchase_id
WHERE p.atelier_id = @aid AND pp.quantity <= 0
UNION ALL
SELECT 'product_negative_stock', COUNT(*)
FROM products
WHERE atelier_id = @aid
  AND deleted_at IS NULL
  AND quantity < 0
UNION ALL
SELECT 'product_zero_purchase_price', COUNT(*)
FROM products
WHERE atelier_id = @aid
  AND deleted_at IS NULL
  AND IFNULL(purchase_price, 0) = 0
  AND IFNULL(quantity, 0) > 0;

-- ========== ۵) نمونه فاکتورهای مشکوک کاربر (قیمت صفر / زیر بهای تمام‌شده / بدون موبایل) ==========
SELECT
    p.id AS purchase_id,
    p.phone,
    p.payment_type,
    COALESCE(pp.item_name, pr.name) AS item_name,
    pp.quantity,
    ROUND(pp.purchase_price, 0) AS purchase_price,
    ROUND(pp.sale_price, 0) AS sale_price,
    ROUND(IFNULL(p.discount_amount, 0), 0) AS discount_amount,
    CASE
        WHEN IFNULL(pp.sale_price, 0) = 0 THEN 'قیمت فروش صفر'
        WHEN pp.purchase_price > pp.sale_price THEN 'فروش زیر بهای تمام‌شده'
        WHEN p.phone IS NULL OR p.phone = '' THEN 'بدون موبایل'
        ELSE 'سایر'
    END AS why,
    p.created_at
FROM purchased_products pp
JOIN purchases p ON p.id = pp.purchase_id
LEFT JOIN products pr ON pr.id = pp.product_id
WHERE p.atelier_id = @aid
  AND (
        IFNULL(pp.sale_price, 0) = 0
     OR (pp.sale_price > 0 AND pp.purchase_price > pp.sale_price)
     OR p.phone IS NULL
     OR p.phone = ''
  )
ORDER BY p.created_at DESC
LIMIT 50;

-- ========== ۶) اعتبار مشتری: مانده فعلی در برابر گردش فروش/برگشت/هدیه ==========
SELECT
    u.phone,
    u.name,
    ROUND(u.credit, 0) AS wallet_credit,
    ROUND(IFNULL(x.earned, 0), 0) AS credit_earned,
    ROUND(IFNULL(x.used, 0), 0) AS credit_used,
    ROUND(IFNULL(g.granted, 0), 0) AS granted,
    ROUND(IFNULL(r.used_refund, 0), 0) AS return_used_refund,
    ROUND(IFNULL(r.earned_rev, 0), 0) AS return_earned_reversed,
    ROUND(
        IFNULL(x.earned, 0) - IFNULL(x.used, 0) + IFNULL(g.granted, 0),
        0
    ) AS expected_if_purchases_already_net,
    ROUND(
        u.credit - (IFNULL(x.earned, 0) - IFNULL(x.used, 0) + IFNULL(g.granted, 0)),
        0
    ) AS wallet_gap
FROM user_shiksho u
LEFT JOIN (
    SELECT phone,
           SUM(IFNULL(credit_earned, 0)) AS earned,
           SUM(IFNULL(credit_used, 0)) AS used
    FROM purchases
    WHERE atelier_id = @aid AND phone IS NOT NULL AND phone <> ''
    GROUP BY phone
) x ON x.phone = u.phone
LEFT JOIN (
    SELECT phone, SUM(amount) AS granted
    FROM user_credit_grants
    WHERE atelier_id = @aid AND credit_type = 'regular'
    GROUP BY phone
) g ON g.phone = u.phone
LEFT JOIN (
    SELECT
        COALESCE(r.phone, p.phone) AS phone,
        SUM(IFNULL(r.credit_used_refund, 0)) AS used_refund,
        SUM(IFNULL(r.credit_earned_reversed, 0)) AS earned_rev
    FROM purchase_item_returns r
    LEFT JOIN purchases p ON p.id = r.purchase_id
    WHERE r.atelier_id = @aid
    GROUP BY COALESCE(r.phone, p.phone)
) r ON r.phone = u.phone
WHERE u.atelier_id = @aid
HAVING ABS(wallet_gap) >= 1000
ORDER BY ABS(wallet_gap) DESC
LIMIT 40;

-- ========== ۷) برگشت‌هایی که مبلغ برگشت با تعداد × قیمت نمی‌خواند ==========
SELECT
    r.id AS return_id,
    r.purchase_id,
    r.phone,
    r.quantity,
    ROUND(r.sale_price, 0) AS sale_price,
    ROUND(r.return_sale_total, 0) AS return_sale_total,
    ROUND(r.quantity * r.sale_price, 0) AS expected_total,
    ROUND(r.return_sale_total - (r.quantity * r.sale_price), 0) AS gap,
    r.created_at
FROM purchase_item_returns r
WHERE r.atelier_id = @aid
  AND ABS(IFNULL(r.return_sale_total, 0) - (r.quantity * r.sale_price)) >= 1000
ORDER BY ABS(IFNULL(r.return_sale_total, 0) - (r.quantity * r.sale_price)) DESC
LIMIT 30;

-- ========== ۸) اقساط: جمع قسط‌ها در برابر فاکتور ==========
SELECT
    p.id AS purchase_id,
    p.phone,
    p.installment_count,
    ROUND(p.total_amount, 0) AS total_amount,
    ROUND(IFNULL(p.credit_used, 0), 0) AS credit_used,
    ROUND(IFNULL(p.card_amount, 0) + IFNULL(p.cash_amount, 0), 0) AS paid_now,
    COUNT(i.id) AS installment_rows,
    ROUND(IFNULL(SUM(i.amount), 0), 0) AS installments_sum,
    SUM(i.is_paid) AS paid_rows,
    ROUND(IFNULL(SUM(CASE WHEN i.is_paid = 1 THEN i.amount ELSE 0 END), 0), 0) AS paid_sum,
    ROUND(IFNULL(SUM(i.amount), 0) - p.total_amount, 0) AS gap_vs_total,
    p.created_at
FROM purchases p
LEFT JOIN installments i ON i.purchase_id = p.id
WHERE p.atelier_id = @aid
  AND p.payment_type = 'installment'
GROUP BY p.id, p.phone, p.installment_count, p.total_amount, p.credit_used, p.card_amount, p.cash_amount, p.created_at
HAVING ABS(IFNULL(SUM(i.amount), 0) - p.total_amount) >= 1000
    OR COUNT(i.id) = 0
ORDER BY p.created_at DESC
LIMIT 30;

-- ========== ۹) دفتر حسابداری ==========
SELECT
    ROUND(SUM(l.debit), 0) AS turnover_debit,
    ROUND(SUM(l.credit), 0) AS turnover_credit,
    CASE WHEN ABS(SUM(l.debit) - SUM(l.credit)) < 1 THEN 'متوازن' ELSE 'نامتوازن' END AS trial_check,
    ROUND(SUM(CASE WHEN a.code = '411' THEN l.credit - l.debit ELSE 0 END), 0) AS sales_411,
    ROUND(SUM(CASE WHEN a.code = '412' THEN l.debit - l.credit ELSE 0 END), 0) AS discounts_412,
    ROUND(SUM(CASE WHEN a.code = '511' THEN l.debit - l.credit ELSE 0 END), 0) AS cogs_511,
    ROUND(SUM(CASE WHEN a.code = '613' THEN l.debit - l.credit ELSE 0 END), 0) AS loyalty_613
FROM accounting_lines l
JOIN accounting_vouchers v ON v.id = l.voucher_id
JOIN accounting_accounts a ON a.id = l.account_id
WHERE v.atelier_id = @aid
  AND v.status IN ('posted', 'reversed');

SELECT
    v.id,
    v.number,
    v.source_type,
    v.source_id,
    LEFT(v.description, 80) AS description,
    ROUND(SUM(l.debit), 0) AS debit,
    ROUND(SUM(l.credit), 0) AS credit,
    ROUND(SUM(l.debit) - SUM(l.credit), 0) AS gap
FROM accounting_vouchers v
JOIN accounting_lines l ON l.voucher_id = v.id
WHERE v.atelier_id = @aid
GROUP BY v.id, v.number, v.source_type, v.source_id, v.description
HAVING ABS(SUM(l.debit) - SUM(l.credit)) >= 1
ORDER BY v.number
LIMIT 30;

-- ========== ۱۰) RFM ذخیره‌شده در برابر محاسبه مجدد از فروش ==========
SELECT
    live.phone,
    live.frequency AS live_frequency,
    m.frequency AS stored_frequency,
    ROUND(live.monetary, 0) AS live_monetary,
    ROUND(m.monetary, 0) AS stored_monetary,
    DATEDIFF(NOW(), live.last_purchase_at) AS live_recency,
    m.recency_days AS stored_recency,
    s.primary_segment,
    m.computed_at
FROM (
    SELECT
        phone,
        COUNT(*) AS frequency,
        SUM(total_amount) AS monetary,
        MAX(created_at) AS last_purchase_at
    FROM purchases
    WHERE atelier_id = @aid
      AND phone IS NOT NULL AND phone <> ''
      AND total_amount > 0
    GROUP BY phone
) live
LEFT JOIN shop_customer_metrics m
    ON m.atelier_id = @aid AND m.phone = live.phone
LEFT JOIN shop_customer_segments s
    ON s.atelier_id = @aid AND s.phone = live.phone
WHERE m.id IS NULL
   OR m.frequency <> live.frequency
   OR ABS(m.monetary - live.monetary) >= 1000
   OR ABS(m.recency_days - DATEDIFF(NOW(), live.last_purchase_at)) > 1
ORDER BY live.monetary DESC
LIMIT 40;

-- ========== ۱۱) ۲۰ فروش آخر (برای دیدن الگوی کار کاربر) ==========
SELECT
    p.id,
    p.phone,
    p.payment_type,
    ROUND(p.total_amount, 0) AS total_amount,
    ROUND(IFNULL(p.discount_amount, 0), 0) AS discount_amount,
    ROUND(IFNULL(p.credit_used, 0), 0) AS credit_used,
    ROUND(IFNULL(p.credit_earned, 0), 0) AS credit_earned,
    ROUND(IFNULL(p.card_amount, 0), 0) AS card_amount,
    ROUND(IFNULL(p.cash_amount, 0), 0) AS cash_amount,
    (SELECT COUNT(*) FROM purchased_products pp WHERE pp.purchase_id = p.id) AS items,
    p.created_at
FROM purchases p
WHERE p.atelier_id = @aid
ORDER BY p.id DESC
LIMIT 20;
