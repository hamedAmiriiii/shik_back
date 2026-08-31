-- پیامک یادآوری نوبت تعویض روغن (یک‌بار به‌ازای هر مراجعه)
-- معادل migration 2026_08_31_180000_create_oil_reminder_sms_table

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `oil_reminder_sms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `atelier_id` bigint unsigned NOT NULL,
  `oil_visit_id` bigint unsigned NOT NULL,
  `plate` varchar(32) NOT NULL,
  `plate_display` varchar(64) NOT NULL,
  `phone` varchar(11) NOT NULL,
  `next_km` int unsigned NOT NULL,
  `estimated_due_on` date DEFAULT NULL,
  `days_until_due` smallint DEFAULT NULL,
  `message` text NOT NULL,
  `sms_sent` tinyint(1) NOT NULL DEFAULT 0,
  `sms_error` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `oil_reminder_sms_oil_visit_id_unique` (`oil_visit_id`),
  KEY `oil_reminder_sms_atelier_created_index` (`atelier_id`, `created_at`),
  KEY `oil_reminder_sms_atelier_phone_index` (`atelier_id`, `phone`),
  CONSTRAINT `oil_reminder_sms_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `oil_reminder_sms_oil_visit_id_foreign` FOREIGN KEY (`oil_visit_id`) REFERENCES `oil_visits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
