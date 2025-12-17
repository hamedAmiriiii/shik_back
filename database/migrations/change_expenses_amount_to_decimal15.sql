-- تغییر نوع ستون amount در جدول expenses از DECIMAL(10,2) به DECIMAL(15,2)
-- برای پشتیبانی از مقادیر بزرگتر (تا 9999999999999.99)

ALTER TABLE `expenses` 
  MODIFY COLUMN `amount` DECIMAL(15, 2) NOT NULL;

