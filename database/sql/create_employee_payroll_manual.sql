-- تعریف کارمندان فروشگاه
CREATE TABLE IF NOT EXISTS `shop_employees` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(11) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shop_employees_atelier_active_index` (`atelier_id`, `is_active`),
  KEY `shop_employees_atelier_phone_index` (`atelier_id`, `phone`),
  CONSTRAINT `shop_employees_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- کارکرد/حقوق ماهانه کارمندان
CREATE TABLE IF NOT EXISTS `employee_payrolls` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `shop_employee_id` BIGINT UNSIGNED NOT NULL,
  `payroll_year` SMALLINT UNSIGNED NOT NULL,
  `payroll_month` TINYINT UNSIGNED NOT NULL,
  `hours_worked` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `hourly_wage` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `salary_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` ENUM('pending','paid') NOT NULL DEFAULT 'pending',
  `paid_at` TIMESTAMP NULL DEFAULT NULL,
  `paid_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `expense_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `note` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_payroll_unique_month` (`shop_employee_id`, `payroll_year`, `payroll_month`),
  KEY `employee_payroll_atelier_month_index` (`atelier_id`, `payroll_year`, `payroll_month`),
  KEY `employee_payroll_atelier_status_index` (`atelier_id`, `status`),
  CONSTRAINT `employee_payrolls_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_payrolls_employee_id_foreign`
    FOREIGN KEY (`shop_employee_id`) REFERENCES `shop_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تنظیمات پیش‌فرض حقوق ساعتی و ساعات کار ماه
INSERT INTO `settings` (`atelier_id`, `key`, `value`, `created_at`, `updated_at`)
SELECT a.`id`, 'salary_hourly_wage', '0', NOW(), NOW()
FROM `ateliers` a
WHERE NOT EXISTS (
  SELECT 1 FROM `settings` s
  WHERE s.`atelier_id` = a.`id` AND s.`key` = 'salary_hourly_wage'
);

INSERT INTO `settings` (`atelier_id`, `key`, `value`, `created_at`, `updated_at`)
SELECT a.`id`, 'salary_monthly_work_hours', '220', NOW(), NOW()
FROM `ateliers` a
WHERE NOT EXISTS (
  SELECT 1 FROM `settings` s
  WHERE s.`atelier_id` = a.`id` AND s.`key` = 'salary_monthly_work_hours'
);
