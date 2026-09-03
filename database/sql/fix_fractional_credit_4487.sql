-- پاکسازی اعشار ساخته‌شده از برگشت #4131 و فروش #4487
-- فقط همین رکوردهای مشخص. قبل از اجرا یک بکاپ بگیرید.

-- برگشت: ۸۴۰۰۰ × نسبت اقلام برگشتی → ۵۷۱۱۸.۸ → تومان کامل ۵۷۱۱۹
UPDATE `purchase_item_returns`
SET `credit_earned_reversed` = 57119.00
WHERE `id` = 166 AND `purchase_id` = 4131;

UPDATE `purchases`
SET `credit_earned` = 26881.00
WHERE `id` = 4131;

UPDATE `purchases`
SET
  `credit_used` = 1932881.00,
  `cash_amount` = 174119.00
WHERE `id` = 4487
  AND `credit_used` = 1932881.20
  AND `cash_amount` = 174118.80;

UPDATE `expenses`
SET `amount` = 1848881.00
WHERE `id` = 268 AND `credit_source` = 'purchase_return' AND `credit_source_id` = 166;

UPDATE `expenses`
SET `amount` = 1932881.00
WHERE `id` = 269 AND `credit_source` = 'loyalty_purchase' AND `credit_source_id` = 4487;

-- اگر ستون credit_earned_reversed روی returned_products هست:
UPDATE `returned_products`
SET `credit_earned_reversed` = 57119.00
WHERE `purchase_id` = 4131
  AND ABS(`credit_earned_reversed` - 57118.80) < 0.05;
