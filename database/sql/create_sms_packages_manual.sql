-- بسته‌های پیامکی فروشگاه + درخواست‌های خرید
-- قیمت‌ها به ریال ذخیره می‌شوند: ۵۰۰٬۰۰۰ تومان = ۵٬۰۰۰٬۰۰۰ ریال | ۱٬۰۰۰٬۰۰۰ تومان = ۱۰٬۰۰۰٬۰۰۰ ریال

CREATE TABLE IF NOT EXISTS `sms_packages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `sms_count` INT UNSIGNED NOT NULL,
  `price_rial` BIGINT UNSIGNED NULL DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sms_package_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `sms_package_id` BIGINT UNSIGNED NOT NULL,
  `sms_count` INT UNSIGNED NOT NULL,
  `price_rial` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  `requested_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `reviewed_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
  `admin_note` VARCHAR(500) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sms_package_orders_atelier_id_status_index` (`atelier_id`, `status`),
  KEY `sms_package_orders_status_created_at_index` (`status`, `created_at`),
  CONSTRAINT `sms_package_orders_sms_package_id_foreign`
    FOREIGN KEY (`sms_package_id`) REFERENCES `sms_packages` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- درج بسته‌ها (اگر وجود ندارند)
INSERT INTO `sms_packages` (`name`, `sms_count`, `price_rial`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'بسته ۳۰۰۰ پیامکی', 3000, 5000000, 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `sms_packages` WHERE `sms_count` = 3000);

INSERT INTO `sms_packages` (`name`, `sms_count`, `price_rial`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'بسته ۶۰۰۰ پیامکی', 6000, 10000000, 1, 2, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `sms_packages` WHERE `sms_count` = 6000);

-- به‌روزرسانی قیمت بسته‌های موجود
UPDATE `sms_packages`
SET `price_rial` = 5000000,
    `name` = 'بسته ۳۰۰۰ پیامکی',
    `updated_at` = NOW()
WHERE `sms_count` = 3000;

UPDATE `sms_packages`
SET `price_rial` = 10000000,
    `name` = 'بسته ۶۰۰۰ پیامکی',
    `updated_at` = NOW()
WHERE `sms_count` = 6000;
