-- وصل کردن فروش POS به کالاهای تولیدی و مواد اولیه
-- معادل migration 2026_08_23_120000
-- اگر ستونی از قبل هست، همان خط ALTER را رد کنید.

ALTER TABLE `produced_goods`
    ADD COLUMN `sale_price` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `name`;

ALTER TABLE `raw_materials`
    ADD COLUMN `sale_price` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `name`;

ALTER TABLE `productions`
    ADD COLUMN `remaining_kg` DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER `quantity_kg`;

UPDATE `productions` SET `remaining_kg` = `quantity_kg` WHERE `remaining_kg` = 0;

ALTER TABLE `purchased_products`
    MODIFY `product_id` BIGINT UNSIGNED NULL;

ALTER TABLE `purchased_products`
    ADD COLUMN `produced_good_id` BIGINT UNSIGNED NULL AFTER `product_id`,
    ADD COLUMN `raw_material_id` BIGINT UNSIGNED NULL AFTER `produced_good_id`,
    ADD COLUMN `item_name` VARCHAR(255) NULL AFTER `raw_material_id`;

CREATE TABLE IF NOT EXISTS `purchase_stock_consumptions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `purchased_product_id` BIGINT UNSIGNED NOT NULL,
    `production_id` BIGINT UNSIGNED NULL,
    `raw_material_lot_id` BIGINT UNSIGNED NULL,
    `quantity_kg` DECIMAL(12,3) NOT NULL,
    `restored_kg` DECIMAL(12,3) NOT NULL DEFAULT 0,
    `price_per_kg` DECIMAL(15,2) NOT NULL,
    `cost` DECIMAL(15,2) NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `purchase_stock_consumptions_line` (`purchased_product_id`),
    CONSTRAINT `purchase_stock_consumptions_line_fk`
        FOREIGN KEY (`purchased_product_id`) REFERENCES `purchased_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `purchase_item_returns`
    MODIFY `product_id` BIGINT UNSIGNED NULL;

ALTER TABLE `purchase_item_returns`
    ADD COLUMN `produced_good_id` BIGINT UNSIGNED NULL AFTER `product_id`,
    ADD COLUMN `raw_material_id` BIGINT UNSIGNED NULL AFTER `produced_good_id`;
