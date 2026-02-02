-- ایجاد جدول shop_sms_logs برای ثبت پیامک‌های فروشگاه

CREATE TABLE IF NOT EXISTS `shop_sms_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(11) NOT NULL,
  `message` TEXT NOT NULL,
  `purchase_id` VARCHAR(255) NULL,
  `credit_amount` DECIMAL(15, 2) NULL,
  `sms_type` VARCHAR(50) NOT NULL DEFAULT 'purchase',
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `shop_sms_logs_phone_index` (`phone`),
  INDEX `shop_sms_logs_created_at_index` (`created_at`),
  INDEX `shop_sms_logs_sms_type_index` (`sms_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- برای rollback:
-- DROP TABLE IF EXISTS `shop_sms_logs`;

