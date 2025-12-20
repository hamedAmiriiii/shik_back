-- ایجاد جدول settings
CREATE TABLE IF NOT EXISTS `settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(255) NOT NULL UNIQUE,
  `value` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- اضافه کردن setting پیش‌فرض
INSERT INTO `settings` (`key`, `value`, `created_at`, `updated_at`) 
VALUES ('enable_loyalty_credit', '1', NOW(), NOW())
ON DUPLICATE KEY UPDATE `value` = '1';

-- برای rollback:
-- DROP TABLE IF EXISTS `settings`;

