-- اضافه کردن فیلد اعتبار اقساطی به جدول user_shiksho
-- این فیلد جدا از اعتبار عادی کاربر است

ALTER TABLE `user_shiksho` 
  ADD COLUMN `installment_credit` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 AFTER `credit`;

-- برای rollback:
-- ALTER TABLE `user_shiksho` DROP COLUMN `installment_credit`;

