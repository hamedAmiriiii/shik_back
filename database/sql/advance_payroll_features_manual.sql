-- پیشرفته‌سازی حقوق: ردیف فیش، پورسانت، وام، حضور، مالیات/بیمه
-- MySQL / MariaDB — اجرای دستی اگر artisan migrate در دسترس نیست
-- اگر ستونی از قبل وجود دارد، همان خط ALTER مربوطه را رد کنید.

-- ─── shop_employees ───────────────────────────────────────────
ALTER TABLE `shop_employees`
  ADD COLUMN `commission_percent` DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER `hourly_wage`;

ALTER TABLE `shop_employees`
  ADD COLUMN `fixed_monthly_amount` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `commission_percent`;

-- گسترش نوع حقوق: monthly|daily|hourly|commission|mixed|fixed
ALTER TABLE `shop_employees`
  MODIFY COLUMN `salary_type` VARCHAR(32) NOT NULL DEFAULT 'monthly';

-- ─── employee_payrolls ────────────────────────────────────────
ALTER TABLE `employee_payrolls`
  ADD COLUMN `base_earned_amount` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `overtime_amount`;

ALTER TABLE `employee_payrolls`
  ADD COLUMN `items_addition_total` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `base_earned_amount`;

ALTER TABLE `employee_payrolls`
  ADD COLUMN `items_deduction_total` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `items_addition_total`;

ALTER TABLE `employee_payrolls`
  ADD COLUMN `commission_sales_amount` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `items_deduction_total`;

ALTER TABLE `employee_payrolls`
  ADD COLUMN `commission_amount` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `commission_sales_amount`;

ALTER TABLE `employee_payrolls`
  MODIFY COLUMN `salary_type_snapshot` VARCHAR(32) NULL;

-- ─── ردیف‌های فیش (پاداش / مزایا / کسر / مالیات / بیمه) ───────
CREATE TABLE IF NOT EXISTS `employee_payroll_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `payroll_id` BIGINT UNSIGNED NOT NULL,
  `item_type` VARCHAR(32) NOT NULL COMMENT 'bonus|allowance|deduction|tax|insurance',
  `title` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `is_auto` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `note` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_payroll_items_payroll_type_index` (`payroll_id`, `item_type`),
  CONSTRAINT `employee_payroll_items_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_payroll_items_payroll_id_foreign`
    FOREIGN KEY (`payroll_id`) REFERENCES `employee_payrolls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── قالب تکرارشونده ماهانه روی کارمند ───────────────────────
CREATE TABLE IF NOT EXISTS `employee_payroll_item_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `shop_employee_id` BIGINT UNSIGNED NOT NULL,
  `item_type` VARCHAR(32) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `note` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_payroll_item_templates_employee_active_index` (`shop_employee_id`, `is_active`),
  CONSTRAINT `employee_payroll_item_templates_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_payroll_item_templates_employee_id_foreign`
    FOREIGN KEY (`shop_employee_id`) REFERENCES `shop_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── وام قسطی کارمند ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `employee_loans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `shop_employee_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL DEFAULT 'وام کارمند',
  `principal` DECIMAL(15,2) NOT NULL,
  `monthly_installment` DECIMAL(15,2) NOT NULL,
  `remaining` DECIMAL(15,2) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|closed',
  `start_year` SMALLINT UNSIGNED NULL,
  `start_month` TINYINT UNSIGNED NULL,
  `note` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_loans_employee_status_index` (`shop_employee_id`, `status`),
  CONSTRAINT `employee_loans_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_loans_employee_id_foreign`
    FOREIGN KEY (`shop_employee_id`) REFERENCES `shop_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── حضور ساده روزانه ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `employee_attendance_days` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `shop_employee_id` BIGINT UNSIGNED NOT NULL,
  `attendance_year` SMALLINT UNSIGNED NOT NULL,
  `attendance_month` TINYINT UNSIGNED NOT NULL,
  `attendance_day` TINYINT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'present' COMMENT 'present|absent|leave',
  `hours` DECIMAL(8,2) NULL,
  `note` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_attendance_unique_day`
    (`shop_employee_id`, `attendance_year`, `attendance_month`, `attendance_day`),
  CONSTRAINT `employee_attendance_days_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_attendance_days_employee_id_foreign`
    FOREIGN KEY (`shop_employee_id`) REFERENCES `shop_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── ثبت migration در جدول Laravel (اختیاری) ─────────────────
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_08_120000_advance_payroll_features', IFNULL(MAX(`batch`), 0) + 1
FROM `migrations`
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations`
  WHERE `migration` = '2026_11_08_120000_advance_payroll_features'
);
