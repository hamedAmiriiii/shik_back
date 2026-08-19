-- ========================================================
-- سفارش پای میز — Shop Tables & Table Orders
-- معادل migration‌های 2026_08_19_200000 و 200001
-- ========================================================

-- ۱. جدول میزهای فروشگاه
CREATE TABLE IF NOT EXISTS `shop_tables` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `atelier_id` BIGINT UNSIGNED NOT NULL,
    `table_number` SMALLINT UNSIGNED NOT NULL,
    `label` VARCHAR(100) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `shop_tables_unique` (`atelier_id`, `table_number`),
    KEY `shop_tables_atelier_active` (`atelier_id`, `is_active`),
    CONSTRAINT `shop_tables_atelier_id_fk` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۲. فیلدهای میز روی جدول purchases
ALTER TABLE `purchases`
    ADD COLUMN `shop_table_id` BIGINT UNSIGNED NULL AFTER `atelier_id`,
    ADD COLUMN `table_label` VARCHAR(100) NULL AFTER `shop_table_id`,
    ADD KEY `purchases_shop_table_id_index` (`shop_table_id`);

-- ========================================================
-- نکته: هر سفارش پای میز به عنوان یک Purchase با
--   payment_type = 'debt'
--   is_debt_settled = 0
--   shop_table_id = ID میز
-- ذخیره می‌شود و در لیست "منتظر پرداخت" نشان داده می‌شود
-- ========================================================
