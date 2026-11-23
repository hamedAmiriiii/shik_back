-- نوع انبار هر قلم فاکتور (product / consumable / raw) — جدا از stock_target کل فاکتور
-- اگر ستون از قبل وجود دارد این دستور را اجرا نکنید.

ALTER TABLE `invoice_items`
  ADD COLUMN `stock_target` VARCHAR(16) NULL AFTER `unit_type`;
