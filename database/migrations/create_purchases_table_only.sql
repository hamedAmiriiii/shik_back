-- SQL برای ایجاد جدول purchases (سبد خرید)

CREATE TABLE IF NOT EXISTS `purchases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(11) NULL,
  `total_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
  `credit_used` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
  `credit_earned` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

