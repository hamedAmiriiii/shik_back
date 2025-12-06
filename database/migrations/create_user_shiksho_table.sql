-- SQL برای ایجاد جدول user_shiksho (باشگاه مشتریان شیک شو)

CREATE TABLE `user_shiksho` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(11) NOT NULL,
  `credit` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_shiksho_phone_unique` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SQL برای اضافه کردن فیلدهای باشگاه مشتریان به جدول purchased_products

ALTER TABLE `purchased_products` 
  ADD COLUMN `phone` VARCHAR(11) NULL AFTER `purchase_price`,
  ADD COLUMN `total_amount` DECIMAL(15, 2) NULL AFTER `phone`,
  ADD COLUMN `credit_used` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 AFTER `total_amount`,
  ADD COLUMN `credit_earned` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 AFTER `credit_used`;

