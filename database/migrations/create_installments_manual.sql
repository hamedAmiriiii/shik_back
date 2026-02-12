-- ============================================
-- Migration: ایجاد سیستم اقساطی
-- تاریخ: 2026-02-06
-- ============================================

-- 1. ایجاد جدول installments
CREATE TABLE IF NOT EXISTS `installments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `purchase_id` BIGINT UNSIGNED NOT NULL,
    `installment_number` INT NOT NULL COMMENT 'شماره قسط (1, 2, 3, ...)',
    `amount` DECIMAL(10, 2) NOT NULL COMMENT 'مبلغ این قسط',
    `due_date` DATE NOT NULL COMMENT 'تاریخ سررسید',
    `is_paid` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'آیا پرداخت شده؟',
    `paid_at` DATETIME NULL COMMENT 'تاریخ پرداخت',
    `notes` TEXT NULL COMMENT 'یادداشت‌ها',
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `installments_purchase_id_installment_number_index` (`purchase_id`, `installment_number`),
    INDEX `installments_due_date_index` (`due_date`),
    INDEX `installments_is_paid_index` (`is_paid`),
    CONSTRAINT `installments_purchase_id_foreign` 
        FOREIGN KEY (`purchase_id`) 
        REFERENCES `purchases` (`id`) 
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. اضافه کردن فیلدهای اقساطی به جدول purchases
ALTER TABLE `purchases` 
ADD COLUMN `payment_type` ENUM('cash', 'installment') NOT NULL DEFAULT 'cash' COMMENT 'نوع پرداخت: نقدی یا اقساطی' AFTER `credit_earned`;

ALTER TABLE `purchases` 
ADD COLUMN `installment_count` INT NULL COMMENT 'تعداد اقساط' AFTER `payment_type`;

ALTER TABLE `purchases` 
ADD COLUMN `installment_amount` DECIMAL(10, 2) NULL COMMENT 'مبلغ هر قسط' AFTER `installment_count`;

-- ============================================
-- پایان Migration
-- ============================================

