-- درخواست اعطای نمایندگی وبینو (ثبت عمومی بدون لاگین + گرید ادمین)
-- معادل مایگریشن 2026_08_26_140000_create_agency_requests_table.php
-- قابل اجرای مجدد است؛ اگر جدول از قبل ساخته شده باشد فقط بخش کلید خارجی اجرا می‌شود.

CREATE TABLE IF NOT EXISTS `agency_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `state_id` bigint unsigned DEFAULT NULL,
  `city_id` bigint unsigned DEFAULT NULL,
  `state_name` varchar(255) DEFAULT NULL COMMENT 'نام استان در لحظه ثبت',
  `city_name` varchar(255) DEFAULT NULL COMMENT 'نام شهر در لحظه ثبت',
  `phone` varchar(20) NOT NULL,
  `education` varchar(64) NOT NULL COMMENT 'مدرک تحصیلی',
  `status` varchar(32) NOT NULL DEFAULT 'pending' COMMENT 'pending | contacted | approved | rejected',
  `admin_note` text,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agency_requests_phone_index` (`phone`),
  KEY `agency_requests_status_created_at_index` (`status`, `created_at`),
  KEY `agency_requests_state_id_foreign` (`state_id`),
  KEY `agency_requests_city_id_foreign` (`city_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- کلیدهای خارجی اختیاری هستند؛ اعتبارسنجی استان/شهر در سطح اپلیکیشن انجام می‌شود.
-- نوع ستون ارجاع‌دهنده باید دقیقاً با نوع `id` جدول مرجع یکی باشد، وگرنه MySQL
-- خطای 1005/errno 150 می‌دهد. پس نوع را از خود جدول مرجع می‌خوانیم و منطبق می‌کنیم.
-- اگر جدول مرجع InnoDB نباشد یا وجود نداشته باشد، از ساخت کلید صرف‌نظر می‌شود.
-- ---------------------------------------------------------------------------

-- استان
SET @state_type := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'states' AND COLUMN_NAME = 'id'
);
SET @state_engine := (
  SELECT ENGINE FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'states'
);
SET @fk_state := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'agency_requests'
    AND CONSTRAINT_NAME = 'agency_requests_state_id_foreign'
);

SET @sql := IF(@state_type IS NOT NULL AND @state_engine = 'InnoDB' AND @fk_state = 0,
  CONCAT('ALTER TABLE `agency_requests` MODIFY `state_id` ', @state_type, ' NULL'),
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@state_type IS NOT NULL AND @state_engine = 'InnoDB' AND @fk_state = 0,
  'ALTER TABLE `agency_requests`
     ADD CONSTRAINT `agency_requests_state_id_foreign`
     FOREIGN KEY (`state_id`) REFERENCES `states` (`id`) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- شهر
SET @city_type := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cities' AND COLUMN_NAME = 'id'
);
SET @city_engine := (
  SELECT ENGINE FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cities'
);
SET @fk_city := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'agency_requests'
    AND CONSTRAINT_NAME = 'agency_requests_city_id_foreign'
);

SET @sql := IF(@city_type IS NOT NULL AND @city_engine = 'InnoDB' AND @fk_city = 0,
  CONCAT('ALTER TABLE `agency_requests` MODIFY `city_id` ', @city_type, ' NULL'),
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@city_type IS NOT NULL AND @city_engine = 'InnoDB' AND @fk_city = 0,
  'ALTER TABLE `agency_requests`
     ADD CONSTRAINT `agency_requests_city_id_foreign`
     FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
