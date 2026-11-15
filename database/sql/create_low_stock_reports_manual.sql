-- گزارش کش‌شدهٔ کالاهای رو به اتمام (روزی یک‌بار محاسبه)

CREATE TABLE IF NOT EXISTS `low_stock_reports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `calculated_date` DATE NOT NULL,
  `calculated_at` TIMESTAMP NULL,
  `payload` JSON NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `low_stock_reports_atelier_unique` (`atelier_id`),
  KEY `low_stock_reports_atelier_date_idx` (`atelier_id`, `calculated_date`),
  CONSTRAINT `low_stock_reports_atelier_fk` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
