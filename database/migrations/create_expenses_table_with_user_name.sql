-- SQL برای ایجاد دستی جدول expenses (هزینه‌های جاری)
-- این نسخه با user_name به جای user_id

CREATE TABLE IF NOT EXISTS `expenses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_name` VARCHAR(255) NOT NULL,
  `date` DATE NOT NULL,
  `amount` DECIMAL(10, 2) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `type` ENUM('جاری', 'سرمایه') NOT NULL DEFAULT 'جاری',
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_name` (`user_name`),
  KEY `idx_date` (`date`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

