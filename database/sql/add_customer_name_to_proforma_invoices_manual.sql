-- نام مشتری روی پیش‌فاکتور
-- معادل migration 2026_10_17_120000_add_customer_name_to_proforma_invoices.php

ALTER TABLE `proforma_invoices`
  ADD COLUMN `customer_name` VARCHAR(255) NULL AFTER `phone`;
