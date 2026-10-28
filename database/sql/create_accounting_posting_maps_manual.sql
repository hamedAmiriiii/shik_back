-- نگاشت رویدادهای نرم‌افزار به حساب درخت — یک‌بار اجرا کنید.

CREATE TABLE IF NOT EXISTS `accounting_posting_maps` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `event_key` VARCHAR(64) NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accounting_posting_maps_atelier_event_unique` (`atelier_id`, `event_key`),
  KEY `accounting_posting_maps_atelier_id_index` (`atelier_id`),
  KEY `accounting_posting_maps_account_id_index` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
