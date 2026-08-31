-- پاک کردن دادهٔ عملیاتی فروشگاه ۱۳
-- می‌ماند: کالاها، تصاویر کالا، دسته‌بندی، پیوند کالا-دسته، تولیدکننده‌ها
-- می‌ماند: تنظیمات، حساب‌های فروشگاه/تنخواه (خالی)، پرسنل، میزها، بازه‌های اعتبار
-- می‌ماند: درخت کدینگ حسابداری (accounting_accounts) — بدون سند
-- حذف می‌شود: فروش، خرید، فاکتور، هزینه، درآمد، چک، اعتبار، مشتری، تولید، مواد اولیه
-- حذف می‌شود: اسناد و آرتیکل دفتر (accounting_vouchers / accounting_lines) از جمله افتتاحیه
--
-- کل این فایل را یکجا در تب SQL phpMyAdmin اجرا کنید (نه دستوربه‌دستور جدا در نشست‌های مختلف).

SET @aid = 13;

SET FOREIGN_KEY_CHECKS = 0;

-- —— دفتر حسابداری (درخت حساب می‌ماند) ——
DELETE l FROM accounting_lines l
INNER JOIN accounting_vouchers v ON v.id = l.voucher_id
WHERE v.atelier_id = @aid;

DELETE FROM accounting_vouchers WHERE atelier_id = @aid;

-- —— فروش / سبد / میز ——
DELETE ci FROM cart_items ci
INNER JOIN carts c ON c.id = ci.cart_id
WHERE c.atelier_id = @aid;

DELETE toi FROM table_order_items toi
INNER JOIN table_orders t ON t.id = toi.table_order_id
WHERE t.atelier_id = @aid;

DELETE FROM table_orders WHERE atelier_id = @aid;

DELETE psc FROM purchase_stock_consumptions psc
INNER JOIN purchased_products pp ON pp.id = psc.purchased_product_id
INNER JOIN purchases p ON p.id = pp.purchase_id
WHERE p.atelier_id = @aid;

DELETE inst FROM installments inst
INNER JOIN purchases p ON p.id = inst.purchase_id
WHERE p.atelier_id = @aid;

DELETE pp FROM purchased_products pp
INNER JOIN purchases p ON p.id = pp.purchase_id
WHERE p.atelier_id = @aid;

DELETE FROM purchase_item_returns WHERE atelier_id = @aid;
DELETE FROM returned_products WHERE atelier_id = @aid;
DELETE FROM user_credit_grants WHERE atelier_id = @aid;
DELETE FROM shop_sms_logs WHERE atelier_id = @aid;
DELETE FROM purchases WHERE atelier_id = @aid;
DELETE FROM carts WHERE atelier_id = @aid;

-- —— مالی ——
DELETE FROM document_payments WHERE atelier_id = @aid;

DELETE ii FROM invoice_items ii
INNER JOIN invoices i ON i.id = ii.invoice_id
WHERE i.atelier_id = @aid;

DELETE FROM employee_payroll_payments WHERE atelier_id = @aid;
DELETE FROM employee_payrolls WHERE atelier_id = @aid;

DELETE dsrad FROM daily_shop_reconciliation_account_deposits dsrad
INNER JOIN daily_shop_reconciliations r ON r.id = dsrad.reconciliation_id
WHERE r.atelier_id = @aid;

DELETE FROM daily_shop_reconciliations WHERE atelier_id = @aid;
DELETE FROM daily_shop_reconciliation_deposits WHERE atelier_id = @aid;
DELETE FROM shop_account_transfers WHERE atelier_id = @aid;
DELETE FROM manual_trades WHERE atelier_id = @aid;

DELETE FROM cheques WHERE atelier_id = @aid;
DELETE FROM invoices WHERE atelier_id = @aid;
DELETE FROM expenses WHERE atelier_id = @aid;
DELETE FROM incomes WHERE atelier_id = @aid;

-- —— تولید و مواد ——
DELETE pc FROM production_consumptions pc
INNER JOIN productions pr ON pr.id = pc.production_id
WHERE pr.atelier_id = @aid;

DELETE FROM productions WHERE atelier_id = @aid;
DELETE FROM raw_material_lots WHERE atelier_id = @aid;

DELETE cpg FROM category_produced_good cpg
INNER JOIN produced_goods g ON g.id = cpg.produced_good_id
WHERE g.atelier_id = @aid;

DELETE pgi FROM produced_good_ingredients pgi
INNER JOIN produced_goods g ON g.id = pgi.produced_good_id
WHERE g.atelier_id = @aid;

DELETE FROM produced_goods WHERE atelier_id = @aid;
DELETE FROM raw_materials WHERE atelier_id = @aid;

-- —— باشگاه / مشتری ——
DELETE ca FROM customer_addresses ca
INNER JOIN customers c ON c.id = ca.customer_id
WHERE c.atelier_id = @aid;

DELETE FROM customers WHERE atelier_id = @aid;
DELETE FROM user_shiksho WHERE atelier_id = @aid;
DELETE FROM confirmation_codes WHERE atelier_id = @aid;

SET FOREIGN_KEY_CHECKS = 1;

-- کنترل: ستون‌های عملیاتی باید صفر باشند؛ کالا و کدینگ می‌مانند
SELECT
    (SELECT COUNT(*) FROM purchases WHERE atelier_id = @aid) AS purchases,
    (SELECT COUNT(*) FROM invoices WHERE atelier_id = @aid) AS invoices,
    (SELECT COUNT(*) FROM expenses WHERE atelier_id = @aid) AS expenses,
    (SELECT COUNT(*) FROM incomes WHERE atelier_id = @aid) AS incomes,
    (SELECT COUNT(*) FROM cheques WHERE atelier_id = @aid) AS cheques,
    (SELECT COUNT(*) FROM accounting_vouchers WHERE atelier_id = @aid) AS accounting_vouchers,
    (SELECT COUNT(*) FROM accounting_lines l
     INNER JOIN accounting_vouchers v ON v.id = l.voucher_id
     WHERE v.atelier_id = @aid) AS accounting_lines,
    (SELECT COUNT(*) FROM produced_goods WHERE atelier_id = @aid) AS produced_goods,
    (SELECT COUNT(*) FROM raw_materials WHERE atelier_id = @aid) AS raw_materials,
    (SELECT COUNT(*) FROM products WHERE atelier_id = @aid) AS products_kept,
    (SELECT COUNT(*) FROM categories WHERE atelier_id = @aid) AS categories_kept,
    (SELECT COUNT(*) FROM accounting_accounts WHERE atelier_id = @aid) AS accounting_accounts_kept;
