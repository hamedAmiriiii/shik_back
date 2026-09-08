-- یادینو: فقط پلن شش‌ماهه و یک‌ساله + جدول خریداران
CREATE TABLE IF NOT EXISTS `product_plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_slug` varchar(32) NOT NULL,
  `name` varchar(255) NOT NULL,
  `max_users` int unsigned NOT NULL DEFAULT 0,
  `max_videos` int unsigned NOT NULL DEFAULT 4,
  `duration_days` int unsigned NOT NULL,
  `duration_label` varchar(32) DEFAULT NULL,
  `price_rial` bigint unsigned NOT NULL,
  `features` json DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_plans_unique_tier` (`product_slug`, `max_users`, `duration_days`),
  KEY `product_plans_product_slug_index` (`product_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_plan_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_plan_id` bigint unsigned NOT NULL,
  `product_slug` varchar(32) NOT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `amount_rial` bigint unsigned NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `authority` varchar(64) DEFAULT NULL,
  `ref_id` varchar(64) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `gateway` varchar(32) NOT NULL DEFAULT 'zarinpal',
  `return_url` text DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_plan_orders_status_created` (`status`, `created_at`),
  KEY `product_plan_orders_phone` (`phone`),
  KEY `product_plan_orders_email` (`email`),
  KEY `product_plan_orders_product_slug` (`product_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM `product_plans` WHERE `product_slug` = 'class' AND `duration_days` NOT IN (180, 365);

INSERT INTO `product_plans` (`product_slug`, `name`, `max_users`, `max_videos`, `duration_days`, `duration_label`, `price_rial`, `features`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'class', 'سرویس آموزشی ۵ کاربره', 5, 4, 180, 'شش‌ماهه', 24510000, '["تا ۵ کاربر همزمان","زمان برگزاری نامحدود","تا ۴ ویدئو همزمان","پیام‌رسان اختصاصی","ایجاد چند کلاس و جلسه همزمان","اشتراک‌گذاری تصویر","اشتراک‌گذاری تخته"]', 'تا ۵ کاربر همزمان، زمان برگزاری نامحدود، تا ۴ ویدئو همزمان، پیام‌رسان اختصاصی، ایجاد چند کلاس و جلسه همزمان، اشتراک‌گذاری تصویر، اشتراک‌گذاری تخته', 1, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `product_plans` WHERE `product_slug` = 'class' AND `max_users` = 5 AND `duration_days` = 180);

INSERT INTO `product_plans` (`product_slug`, `name`, `max_users`, `max_videos`, `duration_days`, `duration_label`, `price_rial`, `features`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'class', 'سرویس آموزشی ۵ کاربره', 5, 4, 365, 'یک‌ساله', 49020000, '["تا ۵ کاربر همزمان","زمان برگزاری نامحدود","تا ۴ ویدئو همزمان","پیام‌رسان اختصاصی","ایجاد چند کلاس و جلسه همزمان","اشتراک‌گذاری تصویر","اشتراک‌گذاری تخته"]', 'تا ۵ کاربر همزمان، زمان برگزاری نامحدود، تا ۴ ویدئو همزمان، پیام‌رسان اختصاصی، ایجاد چند کلاس و جلسه همزمان، اشتراک‌گذاری تصویر، اشتراک‌گذاری تخته', 1, 2, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `product_plans` WHERE `product_slug` = 'class' AND `max_users` = 5 AND `duration_days` = 365);

INSERT INTO `product_plans` (`product_slug`, `name`, `max_users`, `max_videos`, `duration_days`, `duration_label`, `price_rial`, `features`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'class', 'سرویس آموزشی ۳۰ کاربره', 30, 4, 180, 'شش‌ماهه', 77520000, '["تا ۳۰ کاربر همزمان","زمان برگزاری نامحدود","تا ۴ ویدئو همزمان","پیام‌رسان اختصاصی","ایجاد چند کلاس و جلسه همزمان","اشتراک‌گذاری تصویر","اشتراک‌گذاری تخته"]', 'تا ۳۰ کاربر همزمان، زمان برگزاری نامحدود، تا ۴ ویدئو همزمان، پیام‌رسان اختصاصی، ایجاد چند کلاس و جلسه همزمان، اشتراک‌گذاری تصویر، اشتراک‌گذاری تخته', 1, 3, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `product_plans` WHERE `product_slug` = 'class' AND `max_users` = 30 AND `duration_days` = 180);

INSERT INTO `product_plans` (`product_slug`, `name`, `max_users`, `max_videos`, `duration_days`, `duration_label`, `price_rial`, `features`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'class', 'سرویس آموزشی ۳۰ کاربره', 30, 4, 365, 'یک‌ساله', 139080000, '["تا ۳۰ کاربر همزمان","زمان برگزاری نامحدود","تا ۴ ویدئو همزمان","پیام‌رسان اختصاصی","ایجاد چند کلاس و جلسه همزمان","اشتراک‌گذاری تصویر","اشتراک‌گذاری تخته"]', 'تا ۳۰ کاربر همزمان، زمان برگزاری نامحدود، تا ۴ ویدئو همزمان، پیام‌رسان اختصاصی، ایجاد چند کلاس و جلسه همزمان، اشتراک‌گذاری تصویر، اشتراک‌گذاری تخته', 1, 4, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `product_plans` WHERE `product_slug` = 'class' AND `max_users` = 30 AND `duration_days` = 365);

INSERT INTO `product_plans` (`product_slug`, `name`, `max_users`, `max_videos`, `duration_days`, `duration_label`, `price_rial`, `features`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'class', 'سرویس آموزشی ۵۰ کاربره', 50, 4, 180, 'شش‌ماهه', 111150000, '["تا ۵۰ کاربر همزمان","زمان برگزاری نامحدود","تا ۴ ویدئو همزمان","پیام‌رسان اختصاصی","ایجاد چند کلاس و جلسه همزمان","اشتراک‌گذاری تصویر","اشتراک‌گذاری تخته"]', 'تا ۵۰ کاربر همزمان، زمان برگزاری نامحدود، تا ۴ ویدئو همزمان، پیام‌رسان اختصاصی، ایجاد چند کلاس و جلسه همزمان، اشتراک‌گذاری تصویر، اشتراک‌گذاری تخته', 1, 5, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `product_plans` WHERE `product_slug` = 'class' AND `max_users` = 50 AND `duration_days` = 180);

INSERT INTO `product_plans` (`product_slug`, `name`, `max_users`, `max_videos`, `duration_days`, `duration_label`, `price_rial`, `features`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'class', 'سرویس آموزشی ۵۰ کاربره', 50, 4, 365, 'یک‌ساله', 200640000, '["تا ۵۰ کاربر همزمان","زمان برگزاری نامحدود","تا ۴ ویدئو همزمان","پیام‌رسان اختصاصی","ایجاد چند کلاس و جلسه همزمان","اشتراک‌گذاری تصویر","اشتراک‌گذاری تخته"]', 'تا ۵۰ کاربر همزمان، زمان برگزاری نامحدود، تا ۴ ویدئو همزمان، پیام‌رسان اختصاصی، ایجاد چند کلاس و جلسه همزمان، اشتراک‌گذاری تصویر، اشتراک‌گذاری تخته', 1, 6, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `product_plans` WHERE `product_slug` = 'class' AND `max_users` = 50 AND `duration_days` = 365);

INSERT INTO `product_plans` (`product_slug`, `name`, `max_users`, `max_videos`, `duration_days`, `duration_label`, `price_rial`, `features`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'class', 'سرویس آموزشی ۱۰۰ کاربره', 100, 4, 180, 'شش‌ماهه', 213180000, '["تا ۱۰۰ کاربر همزمان","زمان برگزاری نامحدود","تا ۴ ویدئو همزمان","پیام‌رسان اختصاصی","ایجاد چند کلاس و جلسه همزمان","اشتراک‌گذاری تصویر","اشتراک‌گذاری تخته"]', 'تا ۱۰۰ کاربر همزمان، زمان برگزاری نامحدود، تا ۴ ویدئو همزمان، پیام‌رسان اختصاصی، ایجاد چند کلاس و جلسه همزمان، اشتراک‌گذاری تصویر، اشتراک‌گذاری تخته', 1, 7, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `product_plans` WHERE `product_slug` = 'class' AND `max_users` = 100 AND `duration_days` = 180);

INSERT INTO `product_plans` (`product_slug`, `name`, `max_users`, `max_videos`, `duration_days`, `duration_label`, `price_rial`, `features`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'class', 'سرویس آموزشی ۱۰۰ کاربره', 100, 4, 365, 'یک‌ساله', 376200000, '["تا ۱۰۰ کاربر همزمان","زمان برگزاری نامحدود","تا ۴ ویدئو همزمان","پیام‌رسان اختصاصی","ایجاد چند کلاس و جلسه همزمان","اشتراک‌گذاری تصویر","اشتراک‌گذاری تخته"]', 'تا ۱۰۰ کاربر همزمان، زمان برگزاری نامحدود، تا ۴ ویدئو همزمان، پیام‌رسان اختصاصی، ایجاد چند کلاس و جلسه همزمان، اشتراک‌گذاری تصویر، اشتراک‌گذاری تخته', 1, 8, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `product_plans` WHERE `product_slug` = 'class' AND `max_users` = 100 AND `duration_days` = 365);

INSERT INTO `product_plans` (`product_slug`, `name`, `max_users`, `max_videos`, `duration_days`, `duration_label`, `price_rial`, `features`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'class', 'سرویس آموزشی ۱۵۰ کاربره', 150, 4, 180, 'شش‌ماهه', 311220000, '["تا ۱۵۰ کاربر همزمان","زمان برگزاری نامحدود","تا ۴ ویدئو همزمان","پیام‌رسان اختصاصی","ایجاد چند کلاس و جلسه همزمان","اشتراک‌گذاری تصویر","اشتراک‌گذاری تخته"]', 'تا ۱۵۰ کاربر همزمان، زمان برگزاری نامحدود، تا ۴ ویدئو همزمان، پیام‌رسان اختصاصی، ایجاد چند کلاس و جلسه همزمان، اشتراک‌گذاری تصویر، اشتراک‌گذاری تخته', 1, 9, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `product_plans` WHERE `product_slug` = 'class' AND `max_users` = 150 AND `duration_days` = 180);

INSERT INTO `product_plans` (`product_slug`, `name`, `max_users`, `max_videos`, `duration_days`, `duration_label`, `price_rial`, `features`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'class', 'سرویس آموزشی ۱۵۰ کاربره', 150, 4, 365, 'یک‌ساله', 559740000, '["تا ۱۵۰ کاربر همزمان","زمان برگزاری نامحدود","تا ۴ ویدئو همزمان","پیام‌رسان اختصاصی","ایجاد چند کلاس و جلسه همزمان","اشتراک‌گذاری تصویر","اشتراک‌گذاری تخته"]', 'تا ۱۵۰ کاربر همزمان، زمان برگزاری نامحدود، تا ۴ ویدئو همزمان، پیام‌رسان اختصاصی، ایجاد چند کلاس و جلسه همزمان، اشتراک‌گذاری تصویر، اشتراک‌گذاری تخته', 1, 10, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `product_plans` WHERE `product_slug` = 'class' AND `max_users` = 150 AND `duration_days` = 365);

INSERT INTO `product_plans` (`product_slug`, `name`, `max_users`, `max_videos`, `duration_days`, `duration_label`, `price_rial`, `features`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'class', 'سرویس آموزشی ۲۵۰ کاربره', 250, 4, 180, 'شش‌ماهه', 474810000, '["تا ۲۵۰ کاربر همزمان","زمان برگزاری نامحدود","تا ۴ ویدئو همزمان","پیام‌رسان اختصاصی","ایجاد چند کلاس و جلسه همزمان","اشتراک‌گذاری تصویر","اشتراک‌گذاری تخته"]', 'تا ۲۵۰ کاربر همزمان، زمان برگزاری نامحدود، تا ۴ ویدئو همزمان، پیام‌رسان اختصاصی، ایجاد چند کلاس و جلسه همزمان، اشتراک‌گذاری تصویر، اشتراک‌گذاری تخته', 1, 11, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `product_plans` WHERE `product_slug` = 'class' AND `max_users` = 250 AND `duration_days` = 180);

INSERT INTO `product_plans` (`product_slug`, `name`, `max_users`, `max_videos`, `duration_days`, `duration_label`, `price_rial`, `features`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'class', 'سرویس آموزشی ۲۵۰ کاربره', 250, 4, 365, 'یک‌ساله', 867540000, '["تا ۲۵۰ کاربر همزمان","زمان برگزاری نامحدود","تا ۴ ویدئو همزمان","پیام‌رسان اختصاصی","ایجاد چند کلاس و جلسه همزمان","اشتراک‌گذاری تصویر","اشتراک‌گذاری تخته"]', 'تا ۲۵۰ کاربر همزمان، زمان برگزاری نامحدود، تا ۴ ویدئو همزمان، پیام‌رسان اختصاصی، ایجاد چند کلاس و جلسه همزمان، اشتراک‌گذاری تصویر، اشتراک‌گذاری تخته', 1, 12, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `product_plans` WHERE `product_slug` = 'class' AND `max_users` = 250 AND `duration_days` = 365);

INSERT INTO `product_plans` (`product_slug`, `name`, `max_users`, `max_videos`, `duration_days`, `duration_label`, `price_rial`, `features`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'class', 'سرویس آموزشی ۳۵۰ کاربره', 350, 4, 180, 'شش‌ماهه', 651510000, '["تا ۳۵۰ کاربر همزمان","زمان برگزاری نامحدود","تا ۴ ویدئو همزمان","پیام‌رسان اختصاصی","ایجاد چند کلاس و جلسه همزمان","اشتراک‌گذاری تصویر","اشتراک‌گذاری تخته"]', 'تا ۳۵۰ کاربر همزمان، زمان برگزاری نامحدود، تا ۴ ویدئو همزمان، پیام‌رسان اختصاصی، ایجاد چند کلاس و جلسه همزمان، اشتراک‌گذاری تصویر، اشتراک‌گذاری تخته', 1, 13, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `product_plans` WHERE `product_slug` = 'class' AND `max_users` = 350 AND `duration_days` = 180);

INSERT INTO `product_plans` (`product_slug`, `name`, `max_users`, `max_videos`, `duration_days`, `duration_label`, `price_rial`, `features`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'class', 'سرویس آموزشی ۳۵۰ کاربره', 350, 4, 365, 'یک‌ساله', 1179900000, '["تا ۳۵۰ کاربر همزمان","زمان برگزاری نامحدود","تا ۴ ویدئو همزمان","پیام‌رسان اختصاصی","ایجاد چند کلاس و جلسه همزمان","اشتراک‌گذاری تصویر","اشتراک‌گذاری تخته"]', 'تا ۳۵۰ کاربر همزمان، زمان برگزاری نامحدود، تا ۴ ویدئو همزمان، پیام‌رسان اختصاصی، ایجاد چند کلاس و جلسه همزمان، اشتراک‌گذاری تصویر، اشتراک‌گذاری تخته', 1, 14, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `product_plans` WHERE `product_slug` = 'class' AND `max_users` = 350 AND `duration_days` = 365);

INSERT INTO `product_plans` (`product_slug`, `name`, `max_users`, `max_videos`, `duration_days`, `duration_label`, `price_rial`, `features`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'class', 'سرویس آموزشی ۵۰۰ کاربره', 500, 4, 180, 'شش‌ماهه', 915990000, '["تا ۵۰۰ کاربر همزمان","زمان برگزاری نامحدود","تا ۴ ویدئو همزمان","پیام‌رسان اختصاصی","ایجاد چند کلاس و جلسه همزمان","اشتراک‌گذاری تصویر","اشتراک‌گذاری تخته"]', 'تا ۵۰۰ کاربر همزمان، زمان برگزاری نامحدود، تا ۴ ویدئو همزمان، پیام‌رسان اختصاصی، ایجاد چند کلاس و جلسه همزمان، اشتراک‌گذاری تصویر، اشتراک‌گذاری تخته', 1, 15, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `product_plans` WHERE `product_slug` = 'class' AND `max_users` = 500 AND `duration_days` = 180);

INSERT INTO `product_plans` (`product_slug`, `name`, `max_users`, `max_videos`, `duration_days`, `duration_label`, `price_rial`, `features`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'class', 'سرویس آموزشی ۵۰۰ کاربره', 500, 4, 365, 'یک‌ساله', 1656420000, '["تا ۵۰۰ کاربر همزمان","زمان برگزاری نامحدود","تا ۴ ویدئو همزمان","پیام‌رسان اختصاصی","ایجاد چند کلاس و جلسه همزمان","اشتراک‌گذاری تصویر","اشتراک‌گذاری تخته"]', 'تا ۵۰۰ کاربر همزمان، زمان برگزاری نامحدود، تا ۴ ویدئو همزمان، پیام‌رسان اختصاصی، ایجاد چند کلاس و جلسه همزمان، اشتراک‌گذاری تصویر، اشتراک‌گذاری تخته', 1, 16, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `product_plans` WHERE `product_slug` = 'class' AND `max_users` = 500 AND `duration_days` = 365);
