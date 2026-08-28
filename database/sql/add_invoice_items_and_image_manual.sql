-- جزئیات فاکتور (عنوان، فی، تعداد، کل) + یک عکس برای هر فاکتور
-- معادل migration 2026_08_28_100000
-- اگر ستون/جدول از قبل هست همان دستور را رد کنید.

ALTER TABLE `invoices`
    ADD COLUMN `image_path` VARCHAR(255) NULL AFTER `description`;

CREATE TABLE IF NOT EXISTS `invoice_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `quantity` DECIMAL(12,3) NOT NULL DEFAULT 1,
    `total` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `invoice_items_invoice_id_index` (`invoice_id`),
    CONSTRAINT `invoice_items_invoice_id_foreign`
        FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
