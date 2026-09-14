-- وضعیت تحویل پیامک فروشگاه (سامانه شینا)
-- معادل migration 2026_09_14_120000

ALTER TABLE `shop_sms_logs`
    ADD COLUMN `batch_id` VARCHAR(64) NULL AFTER `sms_type`,
    ADD COLUMN `reference_id` VARCHAR(64) NULL AFTER `batch_id`,
    ADD COLUMN `delivery_status` VARCHAR(40) NULL AFTER `reference_id`,
    ADD COLUMN `provider_datetime` VARCHAR(40) NULL AFTER `delivery_status`,
    ADD COLUMN `status_checked_at` TIMESTAMP NULL AFTER `provider_datetime`;

ALTER TABLE `shop_sms_logs`
    ADD INDEX `shop_sms_logs_delivery_status_index` (`delivery_status`),
    ADD INDEX `shop_sms_logs_reference_id_index` (`reference_id`);
