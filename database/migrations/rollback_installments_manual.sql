-- ============================================
-- Rollback: برگرداندن تغییرات سیستم اقساطی
-- تاریخ: 2026-02-06
-- ============================================

-- توجه: این فایل تمام تغییرات را برمی‌گرداند
-- قبل از اجرا، مطمئن شوید که می‌خواهید تمام داده‌های قسط‌ها را از دست بدهید

-- 1. حذف فیلدهای اقساطی از جدول purchases
ALTER TABLE `purchases` 
DROP COLUMN IF EXISTS `payment_type`;

ALTER TABLE `purchases` 
DROP COLUMN IF EXISTS `installment_count`;

ALTER TABLE `purchases` 
DROP COLUMN IF EXISTS `installment_amount`;

-- 2. حذف جدول installments
DROP TABLE IF EXISTS `installments`;

-- ============================================
-- پایان Rollback
-- ============================================

