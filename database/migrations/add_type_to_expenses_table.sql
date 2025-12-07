-- SQL برای اضافه کردن فیلد type به جدول expenses
-- این فایل برای اجرای دستی در دیتابیس است

-- روش 1: اضافه کردن مستقیم (اگر فیلد وجود ندارد)
ALTER TABLE `expenses` 
ADD COLUMN `type` ENUM('جاری', 'سرمایه') NOT NULL DEFAULT 'جاری' AFTER `title`;

-- روش 2: اگر می‌خواهید ابتدا بررسی کنید که فیلد وجود دارد یا نه
-- ابتدا این کوئری را اجرا کنید:
-- SELECT COLUMN_NAME 
-- FROM INFORMATION_SCHEMA.COLUMNS 
-- WHERE TABLE_SCHEMA = DATABASE() 
-- AND TABLE_NAME = 'expenses' 
-- AND COLUMN_NAME = 'type';

-- اگر نتیجه خالی بود، فیلد وجود ندارد و می‌توانید ALTER TABLE را اجرا کنید
-- اگر نتیجه داشت، فیلد وجود دارد و نیازی به اجرای ALTER TABLE نیست

-- روش 3: اگر فیلد قبلاً وجود دارد و می‌خواهید آن را تغییر دهید:
-- ALTER TABLE `expenses` 
-- MODIFY COLUMN `type` ENUM('جاری', 'سرمایه') NOT NULL DEFAULT 'جاری';

-- روش 4: اگر می‌خواهید فیلد را حذف کنید (rollback):
-- ALTER TABLE `expenses` DROP COLUMN `type`;

