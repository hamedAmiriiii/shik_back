-- سفارش پای میز قبل از پرداخت (معادل migration 2026_08_20_100000)
-- اگر shop_tables را قبلاً ساخته‌اید، فقط همین فایل را اجرا کنید.

CREATE TABLE IF NOT EXISTS `table_orders` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `atelier_id` BIGINT UNSIGNED NOT NULL,
    `shop_table_id` BIGINT UNSIGNED NOT NULL,
    `table_label` VARCHAR(100) NULL,
    `phone` VARCHAR(11) NULL,
    `note` TEXT NULL,
    `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `use_credit` TINYINT(1) NOT NULL DEFAULT 0,
    `payment_method` VARCHAR(30) NULL,
    `receipt_path` VARCHAR(255) NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `purchase_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `table_orders_atelier_status` (`atelier_id`, `status`),
    KEY `table_orders_table_status` (`shop_table_id`, `status`),
    KEY `table_orders_phone_atelier` (`phone`, `atelier_id`),
    CONSTRAINT `table_orders_atelier_id_fk` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `table_orders_shop_table_id_fk` FOREIGN KEY (`shop_table_id`) REFERENCES `shop_tables` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `table_order_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `table_order_id` BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `quantity` DECIMAL(12,3) NOT NULL,
    `purchase_price` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `sale_price` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `size` VARCHAR(100) NULL,
    `color` VARCHAR(100) NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `table_order_items_order_id` (`table_order_id`),
    CONSTRAINT `table_order_items_order_id_fk` FOREIGN KEY (`table_order_id`) REFERENCES `table_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
