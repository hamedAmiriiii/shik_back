-- ظهرنویسی / انتقال چک دریافتی به بستانکار (بدون حرکت صندوق)
-- هر دستور را یک‌بار اجرا کنید؛ اگر ستون از قبل هست خطای Duplicate را نادیده بگیرید.

ALTER TABLE `cheques`
  MODIFY `status` ENUM('pending','cleared','cancelled','transferred') NOT NULL DEFAULT 'pending'
  COMMENT 'pending=در انتظار | cleared=وصول‌شده | cancelled=باطل | transferred=منتقل‌شده (ظهرنویسی)';

ALTER TABLE `cheques` ADD COLUMN `transferred_at` TIMESTAMP NULL DEFAULT NULL AFTER `cleared_at`;
ALTER TABLE `cheques` ADD COLUMN `transferred_to_beneficiary_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `transferred_at`;
ALTER TABLE `cheques` ADD COLUMN `transferred_to_invoice_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `transferred_to_beneficiary_id`;
ALTER TABLE `cheques` ADD COLUMN `transferred_to_expense_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `transferred_to_invoice_id`;
ALTER TABLE `cheques` ADD COLUMN `transfer_document_payment_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `transferred_to_expense_id`;
ALTER TABLE `cheques` ADD COLUMN `transfer_note` TEXT NULL AFTER `transfer_document_payment_id`;
