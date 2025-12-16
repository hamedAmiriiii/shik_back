-- تبدیل user_id به user_name در جدول expenses

-- مرحله 1: حذف foreign key constraint
ALTER TABLE `expenses` DROP FOREIGN KEY `expenses_user_id_foreign`;

-- مرحله 2: حذف ستون user_id
ALTER TABLE `expenses` DROP COLUMN `user_id`;

-- مرحله 3: اضافه کردن ستون user_name
ALTER TABLE `expenses` ADD COLUMN `user_name` VARCHAR(255) NOT NULL AFTER `id`;

-- برای rollback (برگشت به حالت قبل):
-- ALTER TABLE `expenses` DROP COLUMN `user_name`;
-- ALTER TABLE `expenses` ADD COLUMN `user_id` BIGINT UNSIGNED NOT NULL AFTER `id`;
-- ALTER TABLE `expenses` ADD CONSTRAINT `expenses_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

