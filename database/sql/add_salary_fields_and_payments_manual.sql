-- ========================================================
-- سیستم حقوق کارمندان — اجرای کامل از صفر
-- شامل: ساخت جداول پایه + فیلدهای جدید + جدول پرداخت
-- ========================================================

-- ۱. جدول کارمندان فروشگاه (شامل فیلدهای پایه حقوق)
CREATE TABLE IF NOT EXISTS `shop_employees` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id`      BIGINT UNSIGNED NOT NULL,
  `name`            VARCHAR(255) NOT NULL,
  `phone`           VARCHAR(11) NULL,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `base_salary`     DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `base_work_hours` DECIMAL(8, 2)  NOT NULL DEFAULT 0,
  `hourly_wage`     DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `note`            TEXT NULL,
  `created_at`      TIMESTAMP NULL DEFAULT NULL,
  `updated_at`      TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shop_employees_atelier_active_index` (`atelier_id`, `is_active`),
  KEY `shop_employees_atelier_phone_index`  (`atelier_id`, `phone`),
  CONSTRAINT `shop_employees_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۲. جدول فیش‌های حقوقی ماهانه (شامل فیلدهای snapshot + وضعیت partial)
CREATE TABLE IF NOT EXISTS `employee_payrolls` (
  `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id`              BIGINT UNSIGNED NOT NULL,
  `shop_employee_id`        BIGINT UNSIGNED NOT NULL,
  `payroll_year`            SMALLINT UNSIGNED NOT NULL,
  `payroll_month`           TINYINT UNSIGNED NOT NULL,
  `hours_worked`            DECIMAL(10, 2) NOT NULL DEFAULT 0,
  `hourly_wage`             DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `salary_amount`           DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `base_salary_snapshot`    DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `base_work_hours_snapshot` DECIMAL(8, 2) NOT NULL DEFAULT 0,
  `overtime_hours`          DECIMAL(8, 2)  NOT NULL DEFAULT 0,
  `overtime_amount`         DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `status`                  ENUM('pending','partial','paid') NOT NULL DEFAULT 'pending',
  `paid_at`                 TIMESTAMP NULL DEFAULT NULL,
  `paid_by_user_id`         BIGINT UNSIGNED NULL DEFAULT NULL,
  `expense_id`              BIGINT UNSIGNED NULL DEFAULT NULL,
  `note`                    TEXT NULL,
  `created_at`              TIMESTAMP NULL DEFAULT NULL,
  `updated_at`              TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_payroll_unique_month` (`shop_employee_id`, `payroll_year`, `payroll_month`),
  KEY `employee_payroll_atelier_month_index`  (`atelier_id`, `payroll_year`, `payroll_month`),
  KEY `employee_payroll_atelier_status_index` (`atelier_id`, `status`),
  CONSTRAINT `employee_payrolls_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_payrolls_employee_id_foreign`
    FOREIGN KEY (`shop_employee_id`) REFERENCES `shop_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۳. جدول پرداخت‌های چندمرحله‌ای (حقوق، مساعده، سایر)
CREATE TABLE IF NOT EXISTS `employee_payroll_payments` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id`      BIGINT UNSIGNED NOT NULL,
  `payroll_id`      BIGINT UNSIGNED NOT NULL,
  `amount`          DECIMAL(15, 2) NOT NULL,
  `payment_type`    ENUM('salary','advance','other') NOT NULL DEFAULT 'salary',
  `title`           VARCHAR(255) NULL DEFAULT NULL,
  `paid_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `expense_id`      BIGINT UNSIGNED NULL DEFAULT NULL,
  `note`            TEXT NULL,
  `created_at`      TIMESTAMP NULL DEFAULT NULL,
  `updated_at`      TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `epp_atelier_payroll_index` (`atelier_id`, `payroll_id`),
  KEY `epp_payroll_type_index`    (`payroll_id`, `payment_type`),
  CONSTRAINT `epp_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `epp_payroll_id_foreign`
    FOREIGN KEY (`payroll_id`) REFERENCES `employee_payrolls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۴. اگر جداول قبلاً وجود داشتند، فیلدهای جدید را اضافه کنید
--    (در صورتی که جداول با CREATE IF NOT EXISTS بالا ساخته شدند، این ALTER‌ها را اجرا نکنید)

-- ALTER TABLE `shop_employees`
--   ADD COLUMN IF NOT EXISTS `base_salary`     DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `is_active`,
--   ADD COLUMN IF NOT EXISTS `base_work_hours` DECIMAL(8, 2)  NOT NULL DEFAULT 0 AFTER `base_salary`,
--   ADD COLUMN IF NOT EXISTS `hourly_wage`     DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `base_work_hours`,
--   ADD COLUMN IF NOT EXISTS `note`            TEXT NULL AFTER `hourly_wage`;

-- ALTER TABLE `employee_payrolls`
--   ADD COLUMN IF NOT EXISTS `base_salary_snapshot`      DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `salary_amount`,
--   ADD COLUMN IF NOT EXISTS `base_work_hours_snapshot`  DECIMAL(8, 2)  NOT NULL DEFAULT 0 AFTER `base_salary_snapshot`,
--   ADD COLUMN IF NOT EXISTS `overtime_hours`            DECIMAL(8, 2)  NOT NULL DEFAULT 0 AFTER `base_work_hours_snapshot`,
--   ADD COLUMN IF NOT EXISTS `overtime_amount`           DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `overtime_hours`;

-- ALTER TABLE `employee_payrolls`
--   MODIFY `status` ENUM('pending','partial','paid') NOT NULL DEFAULT 'pending';
