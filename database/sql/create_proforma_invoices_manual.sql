-- پیش‌فاکتور فروش
-- معادل migration 2026_09_24_120000_create_proforma_invoices_table.php
-- اگر جدول از قبل هست، این فایل را رد کنید.

CREATE TABLE IF NOT EXISTS `proforma_invoices` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `atelier_id` BIGINT UNSIGNED NOT NULL,
    `phone` VARCHAR(20) NULL,
    `discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `items` JSON NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `proforma_invoices_atelier_id_id_index` (`atelier_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
