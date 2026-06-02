-- بازگرداندن total_amount به جمع واقعی اقلام (قبل از تخفیف فاکتور و اعتبار)
-- discount_amount و credit_used و card/cash بدون تغییر می‌مانند.

UPDATE purchases AS p
INNER JOIN (
    SELECT purchase_id, ROUND(SUM(sale_price * quantity), 2) AS line_gross
    FROM purchased_products
    GROUP BY purchase_id
) AS pp_totals ON pp_totals.purchase_id = p.id
SET p.total_amount = pp_totals.line_gross
WHERE (p.payment_type IS NULL OR p.payment_type != 'installment')
  AND (
      p.credit_used > 0
      OR p.discount_amount > 0
      OR ABS(p.total_amount - pp_totals.line_gross) > 0.02
  );
