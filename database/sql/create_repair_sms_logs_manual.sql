-- پیامک‌های سامانهٔ تعمیرات (لاگ ارسال). موجودی پیامک در repair_settings با کلید sms_balance نگه داشته می‌شود.

CREATE TABLE IF NOT EXISTS `repair_sms_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `phone` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `sms_type` varchar(32) NOT NULL DEFAULT 'notify',
  `sms_parts` smallint unsigned NOT NULL DEFAULT 0,
  `batch_id` varchar(64) DEFAULT NULL,
  `reference_id` varchar(64) DEFAULT NULL,
  `delivery_status` varchar(32) DEFAULT NULL,
  `provider_datetime` varchar(64) DEFAULT NULL,
  `status_checked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `repair_sms_logs_phone_index` (`phone`),
  KEY `repair_sms_logs_sms_type_index` (`sms_type`),
  KEY `repair_sms_logs_delivery_status_index` (`delivery_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `repair_settings` (`key`, `value`, `created_at`, `updated_at`)
SELECT 'sms_balance', '0', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `repair_settings` WHERE `key` = 'sms_balance');
