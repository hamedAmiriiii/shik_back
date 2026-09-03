-- تشخیص مبالغ خرد بعد از برگشت خرید
-- هر بلوک را جدا اجرا کنید و خروجی را بفرستید.

-- 1) خود فاکتورها
SELECT
  p.id,
  p.atelier_id,
  p.phone,
  p.payment_type,
  p.total_amount,
  p.discount_amount,
  p.credit_used,
  p.credit_earned,
  p.card_amount,
  p.cash_amount,
  ROUND(p.card_amount + p.cash_amount + p.credit_used + IFNULL(p.discount_amount, 0), 2) AS sum_settlement,
  ROUND(p.total_amount - (p.card_amount + p.cash_amount + p.credit_used + IFNULL(p.discount_amount, 0)), 2) AS gap_vs_total,
  p.created_at,
  p.updated_at
FROM purchases p
WHERE p.id IN (4487, 4488, 4506, 4507)
ORDER BY p.id;

-- 2) اقلام باقی‌مانده روی همان فاکتورها
SELECT
  pp.purchase_id,
  pp.id AS purchased_product_id,
  pp.product_id,
  COALESCE(pp.item_name, pr.name) AS item_name,
  pp.quantity,
  pp.sale_price,
  pp.purchase_price,
  ROUND(pp.quantity * pp.sale_price, 2) AS line_sale_total
FROM purchased_products pp
LEFT JOIN products pr ON pr.id = pp.product_id
WHERE pp.purchase_id IN (4487, 4488, 4506, 4507)
ORDER BY pp.purchase_id, pp.id;

-- 3) جمع اقلام در برابر مبلغ فاکتور
SELECT
  p.id AS purchase_id,
  p.total_amount,
  ROUND(SUM(pp.quantity * pp.sale_price), 2) AS lines_sum,
  ROUND(p.total_amount - SUM(pp.quantity * pp.sale_price), 2) AS gap_lines
FROM purchases p
LEFT JOIN purchased_products pp ON pp.purchase_id = p.id
WHERE p.id IN (4487, 4488, 4506, 4507)
GROUP BY p.id, p.total_amount
ORDER BY p.id;

-- 4) برگشت‌های همین فاکتورها
SELECT
  r.id,
  r.purchase_id,
  r.purchased_product_id,
  r.product_id,
  COALESCE(pr.name, r.notes) AS product_name,
  r.quantity,
  r.sale_price,
  r.return_sale_total,
  r.credit_used_refund,
  r.credit_earned_reversed,
  r.payment_type,
  r.phone,
  r.user_name,
  r.notes,
  r.created_at
FROM purchase_item_returns r
LEFT JOIN products pr ON pr.id = r.product_id
WHERE r.purchase_id IN (4487, 4488, 4506, 4507)
ORDER BY r.created_at, r.id;

-- 5) مشتری‌های همین فاکتورها (مانده اعتبار فعلی)
SELECT
  u.id AS user_shiksho_id,
  u.atelier_id,
  u.phone,
  u.credit,
  u.installment_credit,
  u.credit_last_updated_at,
  u.updated_at
FROM user_shiksho u
WHERE u.phone IN ('09134417294', '09133953102', '09133877781');

-- 6) همه فروش‌های همین شماره‌ها (قبل و بعد) تا ببینیم اعتبار از کجا آمده
SELECT
  p.id,
  p.phone,
  p.payment_type,
  p.total_amount,
  p.discount_amount,
  p.credit_used,
  p.credit_earned,
  p.card_amount,
  p.cash_amount,
  p.created_at,
  p.updated_at
FROM purchases p
WHERE p.phone IN ('09134417294', '09133953102', '09133877781')
ORDER BY p.phone, p.id;

-- 7) همه برگشت‌های همین شماره‌ها
SELECT
  r.id,
  r.purchase_id,
  r.phone,
  r.quantity,
  r.sale_price,
  r.return_sale_total,
  r.credit_used_refund,
  r.credit_earned_reversed,
  r.user_name,
  r.notes,
  r.created_at
FROM purchase_item_returns r
WHERE r.phone IN ('09134417294', '09133953102', '09133877781')
   OR r.purchase_id IN (
        SELECT id FROM purchases
        WHERE phone IN ('09134417294', '09133953102', '09133877781')
     )
ORDER BY r.created_at, r.id;

-- 8) هزینه/سند اعتبار مرتبط (اگر جدول expenses این ستون‌ها را دارد)
SELECT
  e.id,
  e.atelier_id,
  e.amount,
  e.title,
  e.credit_source,
  e.credit_source_id,
  e.date,
  e.created_at
FROM expenses e
WHERE e.credit_source IN ('loyalty_purchase', 'purchase_return')
  AND (
    e.credit_source_id IN (4487, 4488, 4506, 4507)
    OR e.title LIKE '%4487%'
    OR e.title LIKE '%4488%'
    OR e.title LIKE '%4506%'
    OR e.title LIKE '%4507%'
    OR e.title LIKE '%09134417294%'
    OR e.title LIKE '%09133953102%'
    OR e.title LIKE '%09133877781%'
  )
ORDER BY e.id;

-- 9) اگر 4506 دو بار در لیست آمده، آیا دو ردیف در دیتابیس هست؟
SELECT id, COUNT(*) AS cnt
FROM purchases
WHERE id = 4506
GROUP BY id;
