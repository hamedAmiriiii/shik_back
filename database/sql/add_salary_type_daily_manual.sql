-- نوع حقوق ماهانه / روزانه + روز کارکرد در فیش
-- اجرای دستی روی دیتابیس موجود

ALTER TABLE `shop_employees`
  ADD COLUMN `salary_type` VARCHAR(20) NOT NULL DEFAULT 'monthly' AFTER `is_active`;

ALTER TABLE `employee_payrolls`
  ADD COLUMN `days_worked` DECIMAL(8, 2) NOT NULL DEFAULT 0 AFTER `hours_worked`,
  ADD COLUMN `salary_type_snapshot` VARCHAR(20) NULL DEFAULT NULL AFTER `days_worked`;
