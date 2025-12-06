-- SQL برای پاک کردن تمام داده‌های خرید (برای تست)
-- توجه: این دستورات تمام داده‌های خرید را حذف می‌کند

-- 1. پاک کردن محصولات خریداری شده
DELETE FROM `purchased_products`;

-- 2. پاک کردن سبدهای خرید
DELETE FROM `purchases`;

-- 3. پاک کردن اعتبار کاربران (اختیاری - اگر می‌خواهید اعتبارها هم صفر شود)
DELETE FROM `user_shiksho`;

-- 4. پاک کردن شماره تلفن‌های مشتریان (اختیاری)
-- DELETE FROM `customer_phones`;

-- اگر می‌خواهید از TRUNCATE استفاده کنید (سریع‌تر است و AUTO_INCREMENT را هم reset می‌کند):
-- TRUNCATE TABLE `purchased_products`;
-- TRUNCATE TABLE `purchases`;
-- TRUNCATE TABLE `user_shiksho`;
-- TRUNCATE TABLE `customer_phones`;

