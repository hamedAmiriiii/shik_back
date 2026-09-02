-- نوع پروژه برای کاربر و فروشگاه (shop | oil) + جدول مراجعات تعویض روغن
-- معادل migration 2026_08_31_160000_add_project_type_and_oil_visits

SET NAMES utf8mb4;

-- کاربران
SET @users_has_project := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'project_type'
);
SET @sql := IF(@users_has_project = 0,
  'ALTER TABLE `users` ADD COLUMN `project_type` VARCHAR(16) NOT NULL DEFAULT ''shop'' COMMENT ''shop=فروشگاه وبینو | oil=تعویض روغن'' AFTER `atelier_id`, ADD INDEX `users_project_type_index` (`project_type`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `users` SET `project_type` = 'shop' WHERE `project_type` IS NULL OR `project_type` = '';

-- فروشگاه‌ها
SET @atelier_has_project := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ateliers' AND COLUMN_NAME = 'project_type'
);
SET @sql := IF(@atelier_has_project = 0,
  'ALTER TABLE `ateliers` ADD COLUMN `project_type` VARCHAR(16) NOT NULL DEFAULT ''shop'' COMMENT ''shop=فروشگاه وبینو | oil=تعویض روغن'' AFTER `code`, ADD COLUMN `oil_interval_km` INT UNSIGNED NOT NULL DEFAULT 5000 AFTER `project_type`, ADD INDEX `ateliers_project_type_index` (`project_type`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @atelier_has_interval := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ateliers' AND COLUMN_NAME = 'oil_interval_km'
);
SET @sql := IF(@atelier_has_interval = 0,
  'ALTER TABLE `ateliers` ADD COLUMN `oil_interval_km` INT UNSIGNED NOT NULL DEFAULT 5000 AFTER `project_type`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `ateliers` SET `project_type` = 'shop' WHERE `project_type` IS NULL OR `project_type` = '';

CREATE TABLE IF NOT EXISTS `oil_visits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `atelier_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `plate` varchar(32) NOT NULL,
  `plate_display` varchar(64) NOT NULL,
  `phone` varchar(11) NOT NULL,
  `km` int unsigned NOT NULL,
  `next_km` int unsigned NOT NULL,
  `notes` text DEFAULT NULL,
  `sms_sent` tinyint(1) NOT NULL DEFAULT 0,
  `sms_error` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oil_visits_atelier_plate_index` (`atelier_id`, `plate`),
  KEY `oil_visits_atelier_phone_index` (`atelier_id`, `phone`),
  KEY `oil_visits_atelier_created_at_index` (`atelier_id`, `created_at`),
  CONSTRAINT `oil_visits_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
