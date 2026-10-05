-- پرداخت آنلاین سفارش پای میز + لغو نرم + کالای تولیدی (معادل 2026_10_05_200000)

ALTER TABLE `table_orders`
    ADD COLUMN `gateway_payment_id` BIGINT UNSIGNED NULL AFTER `purchase_id`,
    ADD COLUMN `online_paid_at` TIMESTAMP NULL AFTER `gateway_payment_id`,
    ADD COLUMN `online_ref_id` VARCHAR(64) NULL AFTER `online_paid_at`,
    ADD COLUMN `cancelled_by` VARCHAR(20) NULL AFTER `online_ref_id`,
    ADD COLUMN `cancelled_at` TIMESTAMP NULL AFTER `cancelled_by`;

ALTER TABLE `table_order_items`
    MODIFY `product_id` BIGINT UNSIGNED NULL,
    ADD COLUMN `produced_good_id` BIGINT UNSIGNED NULL AFTER `product_id`,
    ADD COLUMN `raw_material_id` BIGINT UNSIGNED NULL AFTER `produced_good_id`,
    ADD COLUMN `item_name` VARCHAR(255) NULL AFTER `raw_material_id`;
